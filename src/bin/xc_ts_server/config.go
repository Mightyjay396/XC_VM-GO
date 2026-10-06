package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// AutoConfig holds all config values that can be auto-discovered from XC_VM.
type AutoConfig struct {
	DBDSN             string
	ServerID          int
	OpensslExtra      string
	LiveStreamingPass string // loaded from DB later
}

// loadAutoConfig attempts to build a full config from the XC_VM installation
// at xcHome. It reads:
//   - go_db.conf  → DSN for the dedicated Go DB user
//   - config/openssl_extra → OPENSSL_EXTRA for token decryption
//   - server_id via PHP → server identity
//
// Any CLI flag that is explicitly set overrides the auto-detected value.
func loadAutoConfig(xcHome, phpBinPath string) (*AutoConfig, error) {
	ac := &AutoConfig{}
	var errs []string

	// ── 1. DSN from go_db.conf ──
	dsnFile := filepath.Join(xcHome, "bin/xc_ts_server/go_db.conf")
	if data, err := os.ReadFile(dsnFile); err == nil {
		dsn := strings.TrimSpace(string(data))
		if dsn != "" {
			// Add clientFoundRows if not present
			if !strings.Contains(dsn, "clientFoundRows") {
				sep := "?"
				if strings.Contains(dsn, "?") {
					sep = "&"
				}
				dsn += sep + "clientFoundRows=true"
			}
			ac.DBDSN = dsn
			log.Printf("autoconfig: DSN loaded from %s", dsnFile)
		}
	} else {
		errs = append(errs, fmt.Sprintf("go_db.conf: %v", err))
	}

	// ── 2. OPENSSL_EXTRA from config/openssl_extra ──
	extraFile := filepath.Join(xcHome, "config/openssl_extra")
	if data, err := os.ReadFile(extraFile); err == nil {
		val := strings.TrimSpace(string(data))
		if val != "" {
			ac.OpensslExtra = val
			log.Printf("autoconfig: OPENSSL_EXTRA loaded (%d chars)", len(val))
		}
	} else {
		// Fall back to the historical default
		ac.OpensslExtra = "fNiu3XD448xTDa27xoY4"
		log.Printf("autoconfig: using historical OPENSSL_EXTRA fallback")
	}

	// ── 3. SERVER_ID from PHP ──
	if phpBinPath != "" {
		sid, err := extractServerID(phpBinPath, xcHome)
		if err == nil {
			ac.ServerID = sid
			log.Printf("autoconfig: server_id=%d (from PHP)", sid)
		} else {
			errs = append(errs, fmt.Sprintf("server_id: %v", err))
		}
	}

	if len(errs) > 0 {
		log.Printf("autoconfig: partial — %s", strings.Join(errs, "; "))
	}

	return ac, nil
}

// extractServerID runs a small PHP snippet to get the server_id from XC_VM's
// C extension, which reads config.enc.
func extractServerID(phpBin, xcHome string) (int, error) {
	script := fmt.Sprintf(`
define('MAIN_HOME', '%s');
require MAIN_HOME . 'vendor/autoload.php';
$kernel = new \XcVm\Core\Bootstrap\BootKernel();
$kernel->boot(\XcVm\Core\Enum\BootContext::Cli);
$conf = \XC_VM::config_server();
echo json_encode(['server_id' => intval($conf['server_id'] ?? 0), 'is_lb' => intval($conf['is_lb'] ?? 0)]);
`, xcHome)

	cmd := exec.Command(phpBin, "-r", script)
	cmd.Dir = xcHome
	cmd.Env = append(os.Environ(), "HOME="+xcHome, "USER=xc_vm")

	out, err := cmd.Output()
	if err != nil {
		return 0, fmt.Errorf("php exec: %w", err)
	}

	var result struct {
		ServerID int `json:"server_id"`
		IsLB     int `json:"is_lb"`
	}
	if err := json.Unmarshal(out, &result); err != nil {
		return 0, fmt.Errorf("json parse: %w (raw: %s)", err, string(out))
	}

	return result.ServerID, nil
}

// detectServerIDFromDB queries the `servers` table and matches this machine's
// IP addresses to find the correct server_id. This is the fallback when PHP
// CLI cannot resolve server_id (e.g. config.enc DB credentials issue on LBs).
func detectServerIDFromDB(db *sql.DB) (int, error) {
	localIPs := getLocalIPs()
	if len(localIPs) == 0 {
		return 0, fmt.Errorf("no local IPs found")
	}

	rows, err := db.Query("SELECT `id`, `server_ip` FROM `servers`")
	if err != nil {
		return 0, fmt.Errorf("query servers: %w", err)
	}
	defer rows.Close()

	for rows.Next() {
		var id int
		var ip string
		if err := rows.Scan(&id, &ip); err != nil {
			continue
		}
		for _, lip := range localIPs {
			if lip == ip {
				return id, nil
			}
		}
	}
	return 0, fmt.Errorf("no matching server_ip in servers table (local IPs: %v)", localIPs)
}

// getLocalIPs returns all non-loopback IPv4 addresses on this machine.
func getLocalIPs() []string {
	var ips []string
	ifaces, err := net.Interfaces()
	if err != nil {
		return ips
	}
	for _, iface := range ifaces {
		if iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			var ip net.IP
			switch v := addr.(type) {
			case *net.IPNet:
				ip = v.IP
			case *net.IPAddr:
				ip = v.IP
			}
			if ip != nil && ip.To4() != nil {
				ips = append(ips, ip.String())
			}
		}
	}
	return ips
}

// writeGoDBConf creates the go_db.conf file with the DSN for the dedicated
// Go DB user. Called by the deployment script, not by Go itself.
// This is a helper that can be invoked via:
//
//	xc_ts_server -write-db-conf -db-user X -db-pass Y -db-host H -db-port P -db-name N
func writeGoDBConf(xcHome, user, pass, host, port, dbname string) error {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?clientFoundRows=true", user, pass, host, port, dbname)
	confPath := filepath.Join(xcHome, "bin/xc_ts_server/go_db.conf")
	if err := os.WriteFile(confPath, []byte(dsn+"\n"), 0600); err != nil {
		return err
	}
	log.Printf("wrote %s", confPath)
	return nil
}
