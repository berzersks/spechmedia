#include <phpx.h>
#include <cstring>

// Read a slice of the existing Zend string. The caller builds the two-byte
// little-endian entries once, independent of host byte order and alignment.
php::Str php_spechmedia_g711_expand(php::Str source, php::Int offset, php::Int samples, php::Str table) {
    if (offset < 0 || samples < 0 || static_cast<size_t>(offset) > source.length() ||
        static_cast<size_t>(samples) > source.length() - offset || table.length() != 512) {
        zend_value_error("Invalid G.711 range or lookup table");
        return {};
    }
    zend_string *pcm = zend_string_safe_alloc(samples, 2, 0, false);
    const auto *input = reinterpret_cast<const unsigned char *>(source.data()) + offset;
    char *out = ZSTR_VAL(pcm);
    for (php::Int i = 0; i < samples; ++i) {
        std::memcpy(out + 2 * i, table.data() + 2 * input[i], 2);
    }
    out[ZSTR_LEN(pcm)] = '\0';
    return php::Str(pcm, php::Ctor::Move);
}
