package main

// Minimal igbinary v2 encoder/decoder for XC_VM connection records.
//
// XC_VM connection records are flat PHP associative arrays with string keys
// and scalar values (int, string, null). This encoder handles that subset
// and produces output that PHP's igbinary_unserialize() accepts.
//
// Format reference: https://github.com/igbinary/igbinary/blob/master/TECH_NOTES.md

import (
	"encoding/binary"
	"fmt"
	"math"
	"sort"
)

// igbinary type constants (v2)
const (
	igNull       = 0x00
	igRef8       = 0x01
	igRef16      = 0x02
	igRef32      = 0x03
	igBoolFalse  = 0x04
	igBoolTrue   = 0x05
	igLong8P     = 0x06
	igLong8N     = 0x07
	igLong16P    = 0x08
	igLong16N    = 0x09
	igLong32P    = 0x0a
	igLong32N    = 0x0b
	igDouble     = 0x0c
	igStrEmpty   = 0x0d
	igStrID8     = 0x0e
	igStrID16    = 0x0f
	igStrID32    = 0x10
	igStr8       = 0x11
	igStr16      = 0x12
	igStr32      = 0x13
	igArray8     = 0x14
	igArray16    = 0x15
	igArray32    = 0x16
	igLong64P    = 0x1e
	igLong64N    = 0x1f
	igArrayEmpty = 0x23
)

// igbinary header: version 2
var igHeader = []byte{0x00, 0x00, 0x00, 0x02}

// IgbinaryEncode serializes a map to igbinary format v2.
// Keys must be strings. Values can be: int, int64, string, nil, bool.
// Key order is sorted for deterministic output.
func IgbinaryEncode(data map[string]interface{}) ([]byte, error) {
	enc := &igEncoder{
		buf:      make([]byte, 0, 512),
		strTable: make(map[string]int),
	}
	enc.buf = append(enc.buf, igHeader...)
	enc.writeArray(data)
	return enc.buf, nil
}

type igEncoder struct {
	buf      []byte
	strTable map[string]int // string -> table index (for dedup)
	strCount int
}

func (e *igEncoder) writeByte(b byte) {
	e.buf = append(e.buf, b)
}

func (e *igEncoder) writeUint16BE(v uint16) {
	e.buf = append(e.buf, byte(v>>8), byte(v))
}

func (e *igEncoder) writeUint32BE(v uint32) {
	e.buf = append(e.buf, byte(v>>24), byte(v>>16), byte(v>>8), byte(v))
}

func (e *igEncoder) writeUint64BE(v uint64) {
	var b [8]byte
	binary.BigEndian.PutUint64(b[:], v)
	e.buf = append(e.buf, b[:]...)
}

func (e *igEncoder) writeArray(data map[string]interface{}) {
	n := len(data)
	if n == 0 {
		e.writeByte(igArrayEmpty)
		return
	}

	// Write array header
	if n <= 0xFF {
		e.writeByte(igArray8)
		e.writeByte(byte(n))
	} else if n <= 0xFFFF {
		e.writeByte(igArray16)
		e.writeUint16BE(uint16(n))
	} else {
		e.writeByte(igArray32)
		e.writeUint32BE(uint32(n))
	}

	// Sort keys for deterministic output (PHP igbinary preserves insertion
	// order, but sorted is fine for compatibility and testability).
	keys := make([]string, 0, n)
	for k := range data {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	for _, k := range keys {
		e.writeString(k)
		e.writeValue(data[k])
	}
}

func (e *igEncoder) writeString(s string) {
	if s == "" {
		e.writeByte(igStrEmpty)
		return
	}

	// Check string table for dedup
	if idx, ok := e.strTable[s]; ok {
		if idx <= 0xFF {
			e.writeByte(igStrID8)
			e.writeByte(byte(idx))
		} else if idx <= 0xFFFF {
			e.writeByte(igStrID16)
			e.writeUint16BE(uint16(idx))
		} else {
			e.writeByte(igStrID32)
			e.writeUint32BE(uint32(idx))
		}
		return
	}

	// New string — add to table
	e.strTable[s] = e.strCount
	e.strCount++

	l := len(s)
	if l <= 0xFF {
		e.writeByte(igStr8)
		e.writeByte(byte(l))
	} else if l <= 0xFFFF {
		e.writeByte(igStr16)
		e.writeUint16BE(uint16(l))
	} else {
		e.writeByte(igStr32)
		e.writeUint32BE(uint32(l))
	}
	e.buf = append(e.buf, s...)
}

func (e *igEncoder) writeValue(v interface{}) {
	if v == nil {
		e.writeByte(igNull)
		return
	}
	switch val := v.(type) {
	case bool:
		if val {
			e.writeByte(igBoolTrue)
		} else {
			e.writeByte(igBoolFalse)
		}
	case int:
		e.writeInt(int64(val))
	case int8:
		e.writeInt(int64(val))
	case int16:
		e.writeInt(int64(val))
	case int32:
		e.writeInt(int64(val))
	case int64:
		e.writeInt(val)
	case uint:
		e.writeInt(int64(val))
	case uint8:
		e.writeInt(int64(val))
	case uint16:
		e.writeInt(int64(val))
	case uint32:
		e.writeInt(int64(val))
	case uint64:
		if val > math.MaxInt64 {
			e.writeInt(int64(val)) // will wrap, but PHP int64 is the same
		} else {
			e.writeInt(int64(val))
		}
	case float64:
		e.writeByte(igDouble)
		e.writeUint64BE(math.Float64bits(val))
	case string:
		e.writeString(val)
	default:
		// Unsupported type — write as null
		e.writeByte(igNull)
	}
}

func (e *igEncoder) writeInt(v int64) {
	if v >= 0 {
		if v <= 0xFF {
			e.writeByte(igLong8P)
			e.writeByte(byte(v))
		} else if v <= 0xFFFF {
			e.writeByte(igLong16P)
			e.writeUint16BE(uint16(v))
		} else if v <= 0xFFFFFFFF {
			e.writeByte(igLong32P)
			e.writeUint32BE(uint32(v))
		} else {
			e.writeByte(igLong64P)
			e.writeUint64BE(uint64(v))
		}
	} else {
		abs := -v
		if abs <= 0xFF {
			e.writeByte(igLong8N)
			e.writeByte(byte(abs))
		} else if abs <= 0xFFFF {
			e.writeByte(igLong16N)
			e.writeUint16BE(uint16(abs))
		} else if abs <= 0xFFFFFFFF {
			e.writeByte(igLong32N)
			e.writeUint32BE(uint32(abs))
		} else {
			e.writeByte(igLong64N)
			e.writeUint64BE(uint64(abs))
		}
	}
}

// IgbinaryDecode deserializes igbinary v2 data into a map.
// Only handles flat PHP associative arrays with scalar values.
func IgbinaryDecode(data []byte) (map[string]interface{}, error) {
	if len(data) < 5 {
		return nil, fmt.Errorf("igbinary: data too short (%d bytes)", len(data))
	}
	// Check header (version 2)
	if data[0] != 0 || data[1] != 0 || data[2] != 0 || data[3] != 2 {
		return nil, fmt.Errorf("igbinary: unsupported version %d.%d", data[2], data[3])
	}

	dec := &igDecoder{
		data:     data,
		pos:      4,
		strTable: make([]string, 0, 32),
	}
	v, err := dec.readValue()
	if err != nil {
		return nil, err
	}
	m, ok := v.(map[string]interface{})
	if !ok {
		return nil, fmt.Errorf("igbinary: expected array, got %T", v)
	}
	return m, nil
}

type igDecoder struct {
	data     []byte
	pos      int
	strTable []string
}

func (d *igDecoder) readByte() (byte, error) {
	if d.pos >= len(d.data) {
		return 0, fmt.Errorf("igbinary: unexpected EOF at pos %d", d.pos)
	}
	b := d.data[d.pos]
	d.pos++
	return b, nil
}

func (d *igDecoder) readUint16BE() (uint16, error) {
	if d.pos+2 > len(d.data) {
		return 0, fmt.Errorf("igbinary: unexpected EOF at pos %d", d.pos)
	}
	v := binary.BigEndian.Uint16(d.data[d.pos:])
	d.pos += 2
	return v, nil
}

func (d *igDecoder) readUint32BE() (uint32, error) {
	if d.pos+4 > len(d.data) {
		return 0, fmt.Errorf("igbinary: unexpected EOF at pos %d", d.pos)
	}
	v := binary.BigEndian.Uint32(d.data[d.pos:])
	d.pos += 4
	return v, nil
}

func (d *igDecoder) readUint64BE() (uint64, error) {
	if d.pos+8 > len(d.data) {
		return 0, fmt.Errorf("igbinary: unexpected EOF at pos %d", d.pos)
	}
	v := binary.BigEndian.Uint64(d.data[d.pos:])
	d.pos += 8
	return v, nil
}

func (d *igDecoder) readBytes(n int) ([]byte, error) {
	if d.pos+n > len(d.data) {
		return nil, fmt.Errorf("igbinary: unexpected EOF at pos %d, need %d", d.pos, n)
	}
	b := d.data[d.pos : d.pos+n]
	d.pos += n
	return b, nil
}

func (d *igDecoder) readValue() (interface{}, error) {
	t, err := d.readByte()
	if err != nil {
		return nil, err
	}

	switch t {
	case igNull:
		return nil, nil
	case igBoolFalse:
		return false, nil
	case igBoolTrue:
		return true, nil

	case igLong8P:
		b, err := d.readByte()
		return int64(b), err
	case igLong8N:
		b, err := d.readByte()
		return -int64(b), err
	case igLong16P:
		v, err := d.readUint16BE()
		return int64(v), err
	case igLong16N:
		v, err := d.readUint16BE()
		return -int64(v), err
	case igLong32P:
		v, err := d.readUint32BE()
		return int64(v), err
	case igLong32N:
		v, err := d.readUint32BE()
		return -int64(v), err
	case igLong64P:
		v, err := d.readUint64BE()
		return int64(v), err
	case igLong64N:
		v, err := d.readUint64BE()
		return -int64(v), err

	case igDouble:
		v, err := d.readUint64BE()
		return math.Float64frombits(v), err

	case igStrEmpty:
		return "", nil
	case igStr8:
		l, err := d.readByte()
		if err != nil {
			return nil, err
		}
		b, err := d.readBytes(int(l))
		if err != nil {
			return nil, err
		}
		s := string(b)
		d.strTable = append(d.strTable, s)
		return s, nil
	case igStr16:
		l, err := d.readUint16BE()
		if err != nil {
			return nil, err
		}
		b, err := d.readBytes(int(l))
		if err != nil {
			return nil, err
		}
		s := string(b)
		d.strTable = append(d.strTable, s)
		return s, nil
	case igStr32:
		l, err := d.readUint32BE()
		if err != nil {
			return nil, err
		}
		b, err := d.readBytes(int(l))
		if err != nil {
			return nil, err
		}
		s := string(b)
		d.strTable = append(d.strTable, s)
		return s, nil

	case igStrID8:
		idx, err := d.readByte()
		if err != nil {
			return nil, err
		}
		if int(idx) >= len(d.strTable) {
			return nil, fmt.Errorf("igbinary: string ref %d out of range", idx)
		}
		return d.strTable[idx], nil
	case igStrID16:
		idx, err := d.readUint16BE()
		if err != nil {
			return nil, err
		}
		if int(idx) >= len(d.strTable) {
			return nil, fmt.Errorf("igbinary: string ref %d out of range", idx)
		}
		return d.strTable[idx], nil
	case igStrID32:
		idx, err := d.readUint32BE()
		if err != nil {
			return nil, err
		}
		if int(idx) >= len(d.strTable) {
			return nil, fmt.Errorf("igbinary: string ref %d out of range", idx)
		}
		return d.strTable[idx], nil

	case igArrayEmpty:
		return map[string]interface{}{}, nil
	case igArray8:
		n, err := d.readByte()
		if err != nil {
			return nil, err
		}
		return d.readArray(int(n))
	case igArray16:
		n, err := d.readUint16BE()
		if err != nil {
			return nil, err
		}
		return d.readArray(int(n))
	case igArray32:
		n, err := d.readUint32BE()
		if err != nil {
			return nil, err
		}
		return d.readArray(int(n))

	case igRef8, igRef16, igRef32:
		// Object/array references — skip for our flat data
		return nil, fmt.Errorf("igbinary: unsupported ref type 0x%02x", t)

	default:
		return nil, fmt.Errorf("igbinary: unknown type 0x%02x at pos %d", t, d.pos-1)
	}
}

func (d *igDecoder) readArray(count int) (map[string]interface{}, error) {
	m := make(map[string]interface{}, count)
	for i := 0; i < count; i++ {
		// Read key — can be string or int (PHP arrays support both)
		keyVal, err := d.readValue()
		if err != nil {
			return nil, fmt.Errorf("igbinary: array key %d: %w", i, err)
		}
		var key string
		switch k := keyVal.(type) {
		case string:
			key = k
		case int64:
			key = fmt.Sprintf("%d", k)
		default:
			key = fmt.Sprintf("%v", k)
		}

		// Read value
		val, err := d.readValue()
		if err != nil {
			return nil, fmt.Errorf("igbinary: array value for key %q: %w", key, err)
		}
		m[key] = val
	}
	return m, nil
}

// Helper to get an int64 from a decoded igbinary map value.
func igGetInt(m map[string]interface{}, key string, def int64) int64 {
	v, ok := m[key]
	if !ok || v == nil {
		return def
	}
	switch val := v.(type) {
	case int64:
		return val
	case float64:
		return int64(val)
	case int:
		return int64(val)
	case string:
		return def
	case bool:
		if val {
			return 1
		}
		return 0
	default:
		return def
	}
}

// Helper to get a string from a decoded igbinary map value.
func igGetStr(m map[string]interface{}, key string, def string) string {
	v, ok := m[key]
	if !ok || v == nil {
		return def
	}
	switch val := v.(type) {
	case string:
		return val
	case int64:
		return fmt.Sprintf("%d", val)
	default:
		return def
	}
}
