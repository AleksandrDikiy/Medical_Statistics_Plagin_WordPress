<?php
/**
 * class-pdf-parser.php v2.0 — Надійний PDF-парсер на основі /Length
 *
 * Попередня версія використовувала regex для пошуку stream/endstream,
 * що призводило до обрізання потоків де бінарний контент містить
 * підрядок "endstream". Нова версія читає /Length з об'єкту PDF.
 */

namespace MedicalStatistics;

defined( 'ABSPATH' ) || exit;

class PdfParser {

    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * Повертає масив позиційних елементів: [ [page, x, y, text], ... ]
     */
    public static function getItems( string $filePath ): array {
        if ( ! is_readable( $filePath ) ) {
            throw new \RuntimeException( "Файл недоступний: {$filePath}" );
        }
        if ( filesize( $filePath ) > self::MAX_FILE_SIZE ) {
            throw new \RuntimeException( "Файл занадто великий" );
        }
        $data = file_get_contents( $filePath );
        if ( ! $data || substr( $data, 0, 4 ) !== '%PDF' ) {
            throw new \RuntimeException( "Файл не є PDF" );
        }
        return self::extractItems( $data );
    }

    public static function getText( string $filePath ): string {
        $items = self::getItems( $filePath );
        if ( empty( $items ) ) {
            throw new \RuntimeException( "Не вдалось витягнути текст із PDF" );
        }
        return self::itemsToText( $items );
    }

    /* ════════════════════════════════════════════════════════
       ВИТЯГ ЕЛЕМЕНТІВ (по /Length, не по regex)
       ════════════════════════════════════════════════════════ */

    private static function extractItems( string $data ): array {
        $all  = [];
        $page = 0;

        // Знаходимо всі PDF-об'єкти що мають stream
        // Шукаємо патерн: "N 0 obj ... /Length LEN ... stream\n"
        $pos = 0;
        $len = strlen( $data );

        while ( $pos < $len ) {
            // Шукаємо початок об'єкту: "N 0 obj"
            $objPos = strpos( $data, ' 0 obj', $pos );
            if ( $objPos === false ) break;

            // Знаходимо початок словника об'єкту
            $dictStart = strrpos( $data, "\n", $objPos - $len ) ?: 0;
            // Знаходимо /Length в словнику цього об'єкту
            $streamMarker = strpos( $data, 'stream', $objPos );
            if ( $streamMarker === false ) { $pos = $objPos + 6; continue; }

            $dictChunk = substr( $data, $objPos, $streamMarker - $objPos );

            // Витягуємо /Length
            if ( ! preg_match( '/\/Length\s+(\d+)/', $dictChunk, $lm ) ) {
                $pos = $objPos + 6;
                continue;
            }
            $streamLen = (int) $lm[1];

            // Знаходимо точний початок потоку (після "stream\n" або "stream\r\n")
            $streamStart = $streamMarker + 6; // "stream"
            if ( isset( $data[$streamStart] ) && $data[$streamStart] === "\r" ) $streamStart++;
            if ( isset( $data[$streamStart] ) && $data[$streamStart] === "\n" ) $streamStart++;

            // Читаємо точно $streamLen байт
            $rawStream = substr( $data, $streamStart, $streamLen );

            $pos = $streamStart + $streamLen;

            if ( strlen( $rawStream ) < 10 ) continue;

            // Декомпресія
            $content = self::decompress( $rawStream );
            if ( $content === null ) continue;
            if ( strpos( $content, 'BT' ) === false ) continue;

            // Парсимо BT...ET блоки
            $items = self::parseStream( $content );
            if ( empty( $items ) ) continue;

            foreach ( $items as [ $x, $y, $text ] ) {
                $all[] = [ $page, (float) $x, (float) $y, $text ];
            }
            $page++;
        }

        return $all;
    }

    private static function decompress( string $raw ): ?string {
        // Спочатку пробуємо zlib (найчастіший випадок)
        if ( strlen( $raw ) >= 2 && ord($raw[0]) === 0x78 ) {
            $r = @zlib_decode( $raw );
            if ( $r !== false && $r !== '' ) return $r;
            $r = @zlib_decode( $raw, 1024 * 1024 );
            if ( $r !== false && $r !== '' ) return $r;
        }
        $r = @zlib_decode( $raw );
        if ( $r !== false && $r !== '' ) return $r;
        // Якщо нестиснений текст
        if ( strpos( $raw, 'BT' ) !== false ) return $raw;
        return null;
    }

    /* ════════════════════════════════════════════════════════
       ПАРСИНГ BT...ET
       ════════════════════════════════════════════════════════ */

    private static function parseStream( string $content ): array {
        $items = [];
        if ( ! preg_match_all( '/BT\s+(.*?)\s*ET/s', $content, $blocks ) ) return [];

        foreach ( $blocks[1] as $block ) {
            $x = 0.0; $y = 0.0;

            // Позиція: "x y Td" або "a b c d x y Tm"
            if ( preg_match( '/(-?[\d.]+)\s+(-?[\d.]+)\s+Td/', $block, $m ) ) {
                $x = (float) $m[1]; $y = (float) $m[2];
            } elseif ( preg_match( '/-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Tm/', $block, $m ) ) {
                $x = (float) $m[1]; $y = (float) $m[2];
            } else {
                continue;
            }

            // Фільтруємо font glyph streams
            if ( $x < 0 || $x > 700 ) continue;

            $text = self::extractText( $block );
            if ( $text !== '' ) {
                $items[] = [ $x, $y, $text ];
            }
        }
        return $items;
    }

    private static function extractText( string $block ): string {
        $parts = [];

        if ( preg_match( '/\[(.*?)\]\s*TJ/s', $block, $m ) ) {
            preg_match_all( '/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $m[1], $strings );
            foreach ( $strings[1] as $str ) {
                $parts[] = self::decodePdfString( self::unescape( $str ) );
            }
        } elseif ( preg_match( '/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/', $block, $m ) ) {
            $parts[] = self::decodePdfString( self::unescape( $m[1] ) );
        }

        $text = implode( '', $parts );
        $text = str_replace( [ "\xc2\xa0", "\u{00A0}" ], ' ', $text );
        $text = str_replace( [ "\u{2019}", "\u{2018}", "`" ], "'", $text );
        return trim( $text );
    }

    private static function unescape( string $s ): string {
        $s = str_replace( [ '\\(', '\\)', '\\\\' ], [ '(', ')', '\\' ], $s );
        $s = str_replace( [ '\\n', '\\r', '\\t' ], [ "\n", "\r", "\t" ], $s );
        $s = preg_replace_callback( '/\\\\([0-7]{1,3})/', fn($m) => chr( octdec($m[1]) ), $s );
        return $s;
    }

    private static function decodePdfString( string $s ): string {
        if ( $s === '' ) return '';
        $bytes = array_values( unpack( 'C*', $s ) );
        $len   = count( $bytes );

        $is_utf16 = false;
        if ( $len >= 2 ) {
            if ( $bytes[0] === 0xFE && $bytes[1] === 0xFF ) {
                $is_utf16 = true; $bytes = array_slice( $bytes, 2 ); $len -= 2;
            } elseif ( $bytes[0] === 0x04 || ( $bytes[0] === 0x00 && $len >= 4 ) ) {
                $is_utf16 = true;
            }
        }

        if ( ! $is_utf16 ) {
            return mb_convert_encoding( $s, 'UTF-8', 'ISO-8859-1' );
        }

        $result = ''; $i = 0;
        while ( $i + 1 < $len ) {
            $code = ( $bytes[$i] << 8 ) | $bytes[$i+1]; $i += 2;
            if ( $code === 0 ) continue;
            if ( $code >= 0xD800 && $code <= 0xDBFF && $i + 1 < $len ) {
                $low = ( $bytes[$i] << 8 ) | $bytes[$i+1];
                if ( $low >= 0xDC00 && $low <= 0xDFFF ) {
                    $code = 0x10000 + ( ($code - 0xD800) << 10 ) + ($low - 0xDC00);
                    $i += 2;
                }
            }
            $result .= mb_chr( $code, 'UTF-8' );
        }
        return $result;
    }

    /* ════════════════════════════════════════════════════════
       ТЕКСТОВИЙ FALLBACK
       ════════════════════════════════════════════════════════ */

    private static function itemsToText( array $items ): string {
        $lines = [];
        foreach ( $items as [ , $x, $y, $text ] ) {
            $key = (int) round( $y / 3 ) * 3;
            $lines[$key][] = [ $x, $text ];
        }
        krsort( $lines );
        $result = [];
        foreach ( $lines as $row ) {
            usort( $row, fn($a,$b) => $a[0] <=> $b[0] );
            $line = implode( '  ', array_column( $row, 1 ) );
            if ( trim( $line ) !== '' ) $result[] = trim( $line );
        }
        return implode( "\n", $result );
    }
}
