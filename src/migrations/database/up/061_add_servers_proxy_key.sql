-- A proxy's control-channel key (ProxyKey, D8). proxy_key_gen is raised at each
-- install, and the key MAIN derives from it is written to the proxy; once the
-- proxy has signed a request (proxy_signed), its unsigned ones are refused, and
-- it no longer needs MAIN's database (lockdown, the DB allowlist).
ALTER TABLE `servers`
      ADD COLUMN IF NOT EXISTS `proxy_key_gen` int(11) NOT NULL DEFAULT '0' AFTER `relay_seal`,
      ADD COLUMN IF NOT EXISTS `proxy_signed` tinyint(1) NOT NULL DEFAULT '0' AFTER `proxy_key_gen`;
