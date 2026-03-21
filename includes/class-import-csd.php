<?php
/**
 * class-import-csd.php v1.5.0 — Спрощений парсер CSD LAB PDF
 *
 * Спрощення:
 * - name = повна назва показника (ПОКАЗНИК колонка), без розбивки на коди
 * - code_ukr та code_eng видалено з БД
 * - Унікальний ключ для пошуку: name (точний збіг)
 * - Два винятки: Вітамін D та Ціанокобаламін
 *
 * Виправлення look-ahead:
 * - Назва показника може йти ЯК ДО так і ПІСЛЯ рядка з даними
 * - Після data row шукаємо назву в наступних рядках (reversed look)
 * - Скидаємо nameParts після кожного data row
 */

namespace MedicalStatistics;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-pdf-parser.php';

/* Кидається коли замовлення вже існує в БД */
class DuplicateOrderException extends \RuntimeException {
    private int $orderId;
    public function __construct( string $message, int $orderId ) {
        parent::__construct( $message );
        $this->orderId = $orderId;
    }
    public function getOrderId(): int { return $this->orderId; }
}

class ImportCsd {

    private \wpdb  $db;
    private string $t_ind;
    private string $t_ord;
    private string $t_meas;

    private float $xNameMax = 200.0;
    private float $xValMin  = 200.0;
    private float $xValMax  = 350.0;
    private float $xUnitMin = 350.0;
    private float $xUnitMax = 430.0;
    private float $xRefMin  = 430.0;
    private float $xDateMin = 150.0;
    private float $xDateMax = 320.0;

    private const X_HDR_LEFT = 370.0;
    private const X_BR3_MIN  = 370.0;

    public function __construct( \wpdb $wpdb ) {
        $this->db     = $wpdb;
        $this->t_ind  = $wpdb->prefix . 'med_indicator';
        $this->t_ord  = $wpdb->prefix . 'med_ordering';
        $this->t_meas = $wpdb->prefix . 'med_measurement';
    }

    /* ════════════════════════════════════════════════════════ */

    public function import( string $filePath ): int {
        if ( ! is_readable( $filePath ) )
            throw new \RuntimeException( "Файл недоступний: {$filePath}" );
        $items = PdfParser::getItems( $filePath );
        if ( empty( $items ) ) throw new \RuntimeException( 'PDF не містить тексту' );

        /* Перевірка дублювання: витягуємо order_number з PDF ДО збереження.
         * Якщо замовлення вже є в БД → повідомлення без обробки.
         */
        $orderNumber = $this->extractOrderNumber( $items );
        if ( $orderNumber ) {
            med_stat_ensure_tables();
            $existing = (int) $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM {$this->t_ord} WHERE order_number=%s LIMIT 1",
                    $orderNumber
                )
            );
            if ( $existing ) {
                throw new DuplicateOrderException(
                    "Замовлення № {$orderNumber} вже завантажено в систему.",
                    $existing
                );
            }
        }

        $rows    = $this->groupRows( $items );
        $this->detectColumns( $rows );
        $orderId = $this->parseOrder( $rows );
        $this->parseIndicators( $rows, $orderId );
        return $orderId;
    }

    /* Швидкий витяг номера замовлення (CS...) з позиційних елементів */
    private function extractOrderNumber( array $items ): ?string {
        foreach ( $items as [ , $x, $y, $text ] ) {
            if ( preg_match( '/^(CS\d{6,})$/', trim( $text ), $m ) ) {
                return $m[1];
            }
        }
        return null;
    }

    /* ════════════════════════════════════════════════════════
       ГРУПУВАННЯ
       ════════════════════════════════════════════════════════ */

    private function groupRows( array $items ): array {
        $pages = [];
        foreach ( $items as [ $pg, $x, $y, $text ] )
            $pages[$pg][ intval( round( $y / 3 ) * 3 ) ][] = [ (float) $x, $text ];
        $rows = [];
        foreach ( $pages as $pg => $yGroups ) {
            krsort( $yGroups );
            foreach ( $yGroups as $yk => $cells ) {
                usort( $cells, fn($a,$b) => $a[0] <=> $b[0] );
                $rows[] = [ 'page' => $pg, 'y' => $yk, 'cols' => $cells ];
            }
        }
        return $rows;
    }

    /* ════════════════════════════════════════════════════════
       ДЕТЕКЦІЯ МЕЖ КОЛОНОК

       Алгоритм: медіана X з 4-елементних рядків даних:
         col[0] x < 150 → ПОКАЗНИК
         col[1]         → РЕЗУЛЬТАТ
         col[2]         → ОД.
         col[3]         → РЕФЕРН. ЗНАЧ.

       Межі = середина між медіанними X сусідніх колонок.
       ════════════════════════════════════════════════════════ */

    private function detectColumns( array $rows ): void {
        $valXs = []; $unitXs = []; $refXs = [];

        foreach ( $rows as $row ) {
            $cols = $row['cols'];
            if ( count( $cols ) !== 4 ) continue;
            [ $x0, $t0 ] = $cols[0];
            [ $x1, $t1 ] = $cols[1];
            [ $x2, $t2 ] = $cols[2];
            [ $x3, $t3 ] = $cols[3];
            if ( (float)$x0 > 150 ) continue;
            if ( ! preg_match( '/\d+[.,]?\d*\s*-\s*\d+[.,]?\d*/', $t3 ) ) continue;
            $clean = preg_replace( '/^[><]/', '', str_replace( ',', '.', $t1 ) );
            if ( ! is_numeric( $clean ) ) continue;
            $valXs[]  = (float) $x1;
            $unitXs[] = (float) $x2;
            $refXs[]  = (float) $x3;
        }

        if ( count( $valXs ) < 2 ) {
            foreach ( $rows as $row ) {
                $allText = implode( ' ', array_column( $row['cols'], 1 ) );
                if ( ! preg_match( '/РЕЗУЛЬТАТ/', $allText ) || ! preg_match( '/ОД\./', $allText ) ) continue;
                $xR = $xO = $xF = null;
                foreach ( $row['cols'] as [ $x, $t ] ) {
                    if ( $t === 'РЕЗУЛЬТАТ' ) $xR = (float) $x;
                    if ( $t === 'ОД.' )       $xO = (float) $x;
                    if ( preg_match( '/^РЕФЕРЕНТНІ/', $t ) ) $xF = (float) $x;
                }
                if ( $xR === null || $xO === null ) continue;
                $xF ??= $xO + 55;
                $this->xNameMax = ( 59 + $xR ) / 2;
                $this->xValMin  = ( 59 + $xR ) / 2;
                $this->xValMax  = ( $xR + $xO ) / 2;
                $this->xUnitMin = ( $xR + $xO ) / 2;
                $this->xUnitMax = ( $xO + $xF ) / 2;
                $this->xRefMin  = ( $xO + $xF ) / 2;
                $this->xDateMin = $xR - 90;
                $this->xDateMax = $xR + 35;
                return;
            }
            return;
        }

        sort( $valXs ); sort( $unitXs ); sort( $refXs );
        $mV = $valXs[  intdiv( count( $valXs  ), 2 ) ];
        $mU = $unitXs[ intdiv( count( $unitXs ), 2 ) ];
        $mR = $refXs[  intdiv( count( $refXs  ), 2 ) ];

        $this->xNameMax = ( 59 + $mV ) / 2;
        $this->xValMin  = ( 59 + $mV ) / 2;
        $this->xValMax  = ( $mV + $mU ) / 2;
        $this->xUnitMin = ( $mV + $mU ) / 2;
        $this->xUnitMax = ( $mU + $mR ) / 2;
        $this->xRefMin  = ( $mU + $mR ) / 2;
        $this->xDateMin = $mV - 80;
        $this->xDateMax = $mV + 35;
    }

    /* ════════════════════════════════════════════════════════
       ПАРСИНГ ЗАМОВЛЕННЯ
       ════════════════════════════════════════════════════════ */

    private function parseOrder( array $rows ): int {
        $order = [
            'order_number' => null, 'patient_name' => 'Unknown',
            'patient_dob'  => null, 'collection_date' => null,
            'doctor_name'  => null, 'branch_info' => null,
        ];
        $patientParts = []; $branchParts = [];
        $inPatient = false; $inBranch = false;

        foreach ( $rows as $row ) {
            $cols    = $row['cols'];
            $allText = implode( ' ', array_column( $cols, 1 ) );
            if ( preg_match( '/^(?:ПОКАЗНИК|Біохімічні|Гормональні|Загальноклінічні|Комплекси)/u', $allText ) ) break;

            if ( ! $order['order_number'] && preg_match( '/\bCS\d{6,}\b/', $allText, $m ) )
                $order['order_number'] = $m[0];

            foreach ( $cols as [ $x, $text ] ) {
                $key = trim( $text );
                if ( $key === 'Пацієнт:' ) { $inPatient = true; continue; }
                if ( $key === 'Дата народження:' ) {
                    $inPatient = false;
                    $val = $this->nextInRow( $cols, (float)$x, self::X_HDR_LEFT );
                    if ( $val && ! $order['patient_dob'] ) {
                        $ts = $this->parseDate( $val );
                        if ( $ts ) $order['patient_dob'] = date( 'Y-m-d', $ts );
                    }
                    continue;
                }
                if ( $inPatient && (float)$x < self::X_HDR_LEFT && ! preg_match( '/\:/', $text )
                    && preg_match( '/^[А-ЯҐЄІЇа-яґєії\']/u', $text ) )
                    $patientParts[] = $key;

                if ( $key === 'Дата забору:' ) {
                    $val = $this->nextInRow( $cols, (float)$x );
                    if ( $val && ! $order['collection_date'] ) {
                        $ts = $this->parseDate( $val );
                        if ( $ts ) $order['collection_date'] = date( 'Y-m-d H:i:s', $ts );
                    }
                    continue;
                }
                if ( $key === 'Лікар:' ) {
                    $val = $this->nextInRow( $cols, (float)$x );
                    if ( $val && ! $order['doctor_name'] )
                        $order['doctor_name'] = sanitize_text_field( mb_substr( $val, 0, 490 ) );
                    continue;
                }
                if ( $key === 'Забірний пункт:' ) {
                    $inBranch = true;
                    $val = $this->nextInRow( $cols, (float)$x );
                    if ( $val ) $branchParts = [ $val ];
                    continue;
                }
                if ( $key === 'Філіал:' ) { $inBranch = false; continue; }
                if ( $inBranch && (float)$x >= self::X_BR3_MIN && ! preg_match( '/\:/', $text ) )
                    $branchParts[] = $key;
            }
        }

        if ( ! empty( $patientParts ) )
            $order['patient_name'] = sanitize_text_field( mb_substr( implode( ' ', $patientParts ), 0, 490 ) );
        if ( ! empty( $branchParts ) )
            $order['branch_info'] = sanitize_text_field( mb_substr( implode( ' ', $branchParts ), 0, 990 ) );

        med_stat_ensure_tables();
        if ( $order['order_number'] ) {
            $existing = (int) $this->db->get_var(
                $this->db->prepare( "SELECT id FROM {$this->t_ord} WHERE order_number=%s LIMIT 1", $order['order_number'] )
            );
            if ( $existing ) {
                $this->db->update( $this->t_ord, array_filter( [
                    'patient_name'    => $order['patient_name'],
                    'patient_dob'     => $order['patient_dob'],
                    'collection_date' => $order['collection_date'],
                    'doctor_name'     => $order['doctor_name'],
                    'branch_info'     => $order['branch_info'],
                ] ), [ 'id' => $existing ] );
                return $existing;
            }
        }
        $ok = $this->db->insert( $this->t_ord, $order, [ '%s','%s','%s','%s','%s','%s' ] );
        if ( false === $ok ) throw new \RuntimeException( 'DB insert (ordering): ' . $this->db->last_error );
        return (int) $this->db->insert_id;
    }

    private function nextInRow( array $cols, float $afterX, float $maxX = PHP_FLOAT_MAX ): string {
        foreach ( $cols as [ $x, $text ] )
            if ( (float)$x > $afterX && (float)$x < $maxX ) return trim( $text );
        return '';
    }

    /* ════════════════════════════════════════════════════════
       ПАРСИНГ ПОКАЗНИКІВ

       Ключові правила:
       1. name = повна назва з колонки ПОКАЗНИК (без розбивки на коди)
       2. Назва може бути ДО або ПІСЛЯ рядка з даними
       3. Hint = рядок після дати що не є новим показником
       4. Стоп = "Виконавці:"
       ════════════════════════════════════════════════════════ */

    private function parseIndicators( array $rows, int $orderId ): void {
        $category         = '';
        $nameParts        = [];
        $inTable          = false;
        $pendingHint      = null;
        $sectionDate      = null;
        $savedIndicators  = [];
        $inGeneralSection = false;
        $afterDate        = false;
        $count            = count( $rows );

        for ( $i = 0; $i < $count; $i++ ) {
            $row  = $rows[$i];
            $cols = $row['cols'];
            $line = trim( implode( ' ', array_column( $cols, 1 ) ) );

            /* ── Стоп-маркер ── */
            if ( preg_match( '/Виконавці:/', $line ) ) break;
            if ( $this->isSkipLine( $line ) ) { $nameParts = []; continue; }

            /* ── Категорія ── */
            $cat = $this->detectCategory( $line );
            if ( $cat !== null ) {
                if ( $inGeneralSection && $sectionDate && ! empty( $savedIndicators ) )
                    foreach ( $savedIndicators as [ $mId ] )
                        $this->db->update( $this->t_meas, [ 'execution_date' => $sectionDate ], [ 'id' => $mId ] );
                $category = $cat; $inTable = true; $nameParts = []; $afterDate = false;
                $inGeneralSection = in_array( $cat, [ 'Загальноклінічні', 'Загальний аналіз крові' ], true );
                $sectionDate = null; $savedIndicators = []; $pendingHint = null;
                continue;
            }

            if ( ! $inTable ) {
                if ( preg_match( '/ПОКАЗНИК/', $line ) ) $inTable = true;
                continue;
            }

            /* ── Дата ── */
            if ( $this->isDateRow( $cols ) ) {
                $ts = $this->parseDate( $this->getDateFromRow( $cols ) );
                if ( $ts ) {
                    $sectionDate = date( 'Y-m-d H:i:s', $ts );
                    if ( ! $inGeneralSection && ! empty( $savedIndicators ) ) {
                        [ $lastMId ] = end( $savedIndicators );
                        $this->db->update( $this->t_meas, [ 'execution_date' => $sectionDate ], [ 'id' => $lastMId ] );
                    }
                }
                $nameParts = []; $afterDate = true;
                continue;
            }

            /* ── Підказка (hint) — тільки якщо рядок після дати ── */
            if ( $afterDate && ! empty( $savedIndicators ) && $this->isHintLine( $line ) ) {
                [ , $lastIndId ] = end( $savedIndicators );
                $cur = $this->db->get_var(
                    $this->db->prepare( "SELECT interpretation_hint FROM {$this->t_ind} WHERE id=%d", $lastIndId )
                ) ?? '';
                $newHint = $cur ? $cur . ' ' . $line : $line;
                $this->db->update( $this->t_ind,
                    [ 'interpretation_hint' => sanitize_text_field( mb_substr( $newHint, 0, 2000 ) ) ],
                    [ 'id' => $lastIndId ]
                );
                $nameParts = [];
                continue;
            }
            $afterDate = false;

            /* ── Витяг колонок ── */
            $nameCol = trim( $this->getCol( $cols, 0,               $this->xNameMax ) );
            $valCol  = trim( $this->getCol( $cols, $this->xValMin,  $this->xValMax  ) );
            $unitCol = trim( $this->getCol( $cols, $this->xUnitMin, $this->xRefMin  ) );
            $refCol  = trim( $this->getCol( $cols, $this->xRefMin,  PHP_FLOAT_MAX   ) );

            /* Дата в nameCol */
            if ( $nameCol !== '' && $this->isDateText( $nameCol ) ) {
                $ts = $this->parseDate( $nameCol );
                if ( $ts ) {
                    $sectionDate = date( 'Y-m-d H:i:s', $ts );
                    if ( ! $inGeneralSection && ! empty( $savedIndicators ) ) {
                        [ $lastMId ] = end( $savedIndicators );
                        $this->db->update( $this->t_meas, [ 'execution_date' => $sectionDate ], [ 'id' => $lastMId ] );
                    }
                }
                $nameParts = []; continue;
            }

            /* Рядок-підзаголовок групи (дужки з комами) */
            if ( $nameCol !== '' && $valCol === '' && $refCol === ''
                && preg_match( '/\([^)]*,[^)]*\)/u', $nameCol ) ) {
                $pendingHint = sanitize_text_field( mb_substr( $nameCol, 0, 500 ) );
                $nameParts = []; continue;
            }

            /* Пропуск якщо значення не числове */
            if ( $valCol !== '' && ! $this->isNumericValue( $valCol ) ) continue;

            /* ── РЯДОК З ДАНИМИ ── */
            if ( $valCol !== '' && $refCol !== ''
                && preg_match( '/\d+[.,]?\d*\s*-\s*\d+[.,]?\d*/', $refCol ) ) {

                if ( $nameCol !== '' ) $nameParts[] = $nameCol;

                /* Шукаємо назву ПІСЛЯ data row якщо nameParts порожній
                 * (буває коли в PDF назва стоїть нижче за значення — менший Y)
                 * Беремо ТІЛЬКИ один рядок-продовження (частина назви з нового рядка PDF).
                 * Не беремо hint-рядки, дати, категорії.
                 */
                if ( empty( $nameParts ) ) {
                    for ( $j = $i + 1; $j < min( $i + 4, $count ); $j++ ) {
                        $nRow  = $rows[$j];
                        $nName = trim( $this->getCol( $nRow['cols'], 0, $this->xNameMax ) );
                        $nVal  = trim( $this->getCol( $nRow['cols'], $this->xValMin, $this->xValMax ) );
                        $nRef  = trim( $this->getCol( $nRow['cols'], $this->xRefMin, PHP_FLOAT_MAX ) );
                        /* Пропускаємо рядки лише правої колонки */
                        if ( $nName === '' ) continue;
                        /* Зупиняємось на даних, датах, категоріях, підказках */
                        if ( $nVal !== '' || $nRef !== '' ) break;
                        if ( $this->isDateRow( $nRow['cols'] ) || $this->isDateText( $nName ) ) break;
                        if ( $this->isSkipLine( $nName ) ) break;
                        if ( $this->detectCategory( $nName ) !== null ) break;
                        if ( preg_match( '/Виконавці:/', $nName ) ) break;
                        /* ВАЖЛИВО: hint-рядки не є назвою → стоп */
                        if ( $this->isHintLine( $nName ) ) break;
                        /* Нова назва з великої літери (не continuation) → стоп */
                        if ( preg_match( '/^[А-ЯҐЄІЇA-Z]/u', $nName ) && ! preg_match( '/^\(/u', $nName ) ) {
                            $nameParts[] = $nName;
                            $i = $j;
                            break; // беремо цей рядок і зупиняємось
                        }
                        /* Continuation (мала літера або дужка) */
                        $nameParts[] = $nName;
                        $i = $j;
                        $joined = implode( ' ', $nameParts );
                        if ( ! preg_match( '/[-\(]$/', $joined ) ) break;
                    }
                } else {
                    /* Look-ahead: добираємо продовження назви (малі літери, коди в дужках)
                     * Пропускаємо рядки правої колонки (декор).
                     */
                    $j = $i + 1; $steps = 0;
                    while ( $j < $count && $steps < 8 ) {
                        $nRow  = $rows[$j];
                        $nName = trim( $this->getCol( $nRow['cols'], 0, $this->xNameMax ) );
                        $nVal  = trim( $this->getCol( $nRow['cols'], $this->xValMin, $this->xValMax ) );
                        $nRef  = trim( $this->getCol( $nRow['cols'], $this->xRefMin, PHP_FLOAT_MAX ) );

                        if ( $nVal !== '' && $this->isNumericValue( $nVal ) ) break;
                        if ( $nRef !== '' && preg_match( '/\d+[.,]?\d*\s*-\s*\d+[.,]?\d*/', $nRef ) ) break;
                        if ( $nName === '' ) { $j++; $steps++; continue; }
                        if ( $this->isDateRow( $nRow['cols'] ) || $this->isDateText( $nName ) ) break;
                        if ( $this->isSkipLine( $nName ) ) break;
                        if ( $this->detectCategory( $nName ) !== null ) break;
                        if ( preg_match( '/Виконавці:/', $nName ) ) break;

                        $joined  = implode( ' ', $nameParts );
                        $isCont  = (
                            preg_match( '/[-\(]$/u', $joined )
                            || preg_match( '/^[а-яґєіїa-z]/u', $nName )
                            || preg_match( '/^\([^)]+\)(?:\s*\/\s*\S+)?\s*$/u', $nName )
                        );
                        if ( ! $isCont ) {
                            if ( $this->isHintLine( $nName ) ) break;
                            break; // нова назва
                        }

                        $nameParts[] = $nName;
                        $i = $j; $j++; $steps++;
                        /* Якщо назва завершена → стоп */
                        $joined2 = implode( ' ', $nameParts );
                        if ( ! preg_match( '/[-\(]$/', $joined2 ) ) break;
                    }
                }

                $rawName = trim( implode( ' ', $nameParts ) );
                $nameParts = [];

                if ( $rawName === '' ) continue;

                /* Застосовуємо винятки та отримуємо name + hint */
                [ $name, $hint ] = $this->resolveName( $rawName, $pendingHint );

                $cleanVal = (float) str_replace( ',', '.', preg_replace( '/[^0-9.,\-]/', '', $valCol ) );
                [ $min, $max ] = $this->parseRange( $refCol );
                $isNormal = ( ! preg_match( '/^[><]/', $valCol ) && $cleanVal >= $min && $cleanVal <= $max ) ? 1 : 0;

                $indId = $this->saveIndicator( [
                    'name'     => $name,
                    'min'      => $min, 'max' => $max,
                    'measure'  => $unitCol ?: null,
                    'category' => $category,
                    'interpretation_hint' => $hint,
                ] );
                if ( ! $indId ) continue;

                $measId = $this->saveMeasurement( [
                    'id_order'       => $orderId,
                    'id_indicator'   => $indId,
                    'result_value'   => number_format( $cleanVal, 3, '.', '' ),
                    'execution_date' => $inGeneralSection ? null : $sectionDate,
                    'is_normal'      => $isNormal,
                ] );
                if ( $measId ) $savedIndicators[] = [ $measId, $indId ];
                $sectionDate = null; $pendingHint = null;
                continue;
            }

            /* ── Рядок лише з назвою ── */
            if ( $nameCol !== '' && ! $this->isSkipLine( $nameCol ) && ! $this->isDateText( $nameCol ) ) {
                if ( ! $this->isHintLine( $nameCol ) )
                    $nameParts[] = $nameCol;
            }
        }

        /* Секційна дата для Загальноклінічних */
        if ( $inGeneralSection && $sectionDate && ! empty( $savedIndicators ) )
            foreach ( $savedIndicators as [ $measId ] )
                $this->db->update( $this->t_meas, [ 'execution_date' => $sectionDate ], [ 'id' => $measId ] );
    }

    /* ════════════════════════════════════════════════════════
       ВИЗНАЧЕННЯ НАЗВИ (спрощено + винятки)
       ════════════════════════════════════════════════════════ */

    private function resolveName( string $raw, ?string $pendingHint ): array {
        $raw = trim( $raw );

        /* Виняток: 25-гідроксивітамін D → Вітамін D */
        if ( preg_match( '/^25-гідроксивітамін\s+D/u', $raw ) ) {
            return [ 'Вітамін D', $raw ];
        }

        /* Виняток: Ціанокобаламін (вітамін B12) → вітамін B12 */
        if ( preg_match( '/^Ціанокобаламін\s*\(вітамін\s+B12\)/u', $raw ) ) {
            return [ 'вітамін B12', 'Ціанокобаламін' ];
        }

        /* Виняток: Тригліцериди — якщо після назви є hint-текст (через злиття рядків)
         * "Тригліцериди до 1.7 ммоль/л..." → name='Тригліцериди', hint='до 1.7...'
         */
        if ( preg_match( '/^(Тригліцериди)\s+(до\s+.+)/us', $raw, $m ) ) {
            return [
                sanitize_text_field( $m[1] ),
                sanitize_text_field( mb_substr( trim( $m[2] ), 0, 2000 ) ),
            ];
        }
        if ( preg_match( '/^Тригліцериди$/u', trim( $raw ) ) ) {
            return [ 'Тригліцериди', $pendingHint ];
        }

        /* Виняток: Холестерин — якщо після назви є hint-текст
         * "Холестерин до 5.2 ммоль/л..." → name='Холестерин', hint='до 5.2...'
         */
        if ( preg_match( '/^(Холестерин)\s+(до\s+.+)/us', $raw, $m ) ) {
            return [
                sanitize_text_field( $m[1] ),
                sanitize_text_field( mb_substr( trim( $m[2] ), 0, 2000 ) ),
            ];
        }
        if ( preg_match( '/^Холестерин$/u', trim( $raw ) ) ) {
            return [ 'Холестерин', $pendingHint ];
        }

        /* Виняток: Фолієва кислота (сироватка)/ Folic Acid */
        if ( preg_match( '/^Фолієва\s+кислота\s*\(сироватка\)/u', $raw ) ) {
            return [ 'Фолієва кислота (сироватка)/ Folic Acid', $pendingHint ];
        }

        /* Виняток: Ліпопротеїди дуже низької щільності (ЛПДНЩ)/ VLDL
         * Нормалізуємо назву, hint = пустий або залишок тексту
         */
        if ( preg_match( '/^Ліпопротеїди\s+дуже\s+низької\s+щільності/u', $raw ) ) {
            return [ 'Ліпопротеїди дуже низької щільності (ЛПДНЩ)/ VLDL', null ];
        }

        /* Виняток: Ліпопротеїди низької щільності (ЛПНЩ)/ LDLC
         * Якщо після назви є hint-текст → розділяємо
         */
        if ( preg_match( '/^(Ліпопротеїди\s+низької\s+щільності(?:\s*\(ЛПНЩ\))?\s*\/\s*LDLC?)\s*(до\s+.+)?$/us', $raw, $m ) ) {
            $hint = isset( $m[2] ) && $m[2] !== ''
                ? sanitize_text_field( mb_substr( trim( $m[2] ), 0, 2000 ) )
                : $pendingHint;
            return [ 'Ліпопротеїди низької щільності (ЛПНЩ)/ LDLC', $hint ];
        }
        if ( preg_match( '/^Ліпопротеїди\s+низької\s+щільності/u', $raw ) ) {
            // Текст після назви → hint
            $suffix = preg_replace( '/^Ліпопротеїди\s+низької\s+щільності[^\n]*/u', '', $raw );
            $suffix = trim( $suffix );
            return [
                'Ліпопротеїди низької щільності (ЛПНЩ)/ LDLC',
                $suffix !== '' ? sanitize_text_field( mb_substr( $suffix, 0, 2000 ) ) : $pendingHint,
            ];
        }

        /* Виняток: Ліпопротеїди високої щільності (ЛПВЩ)/ НDLC — нормалізуємо назву */
        if ( preg_match( '/^Ліпопротеїди\s+висок/u', $raw ) ) {
            // Відсікаємо можливий hint-текст
            if ( preg_match( '/^(Ліпопротеїди\s+високої\s+щільності[^;\n]*?)\s+(до\s+.+)$/us', $raw, $m ) ) {
                return [
                    sanitize_text_field( trim( $m[1] ) ),
                    sanitize_text_field( mb_substr( trim( $m[2] ), 0, 2000 ) ),
                ];
            }
        }

        /* Стандарт: name = повна назва, hint = pendingHint (підзаголовок групи) */
        $name = sanitize_text_field( mb_substr( $raw, 0, 490 ) );
        return [ $name, $pendingHint ];
    }

    /* ════════════════════════════════════════════════════════
       ДОПОМІЖНІ
       ════════════════════════════════════════════════════════ */

    private function getCol( array $cols, float $xMin, float $xMax ): string {
        $parts = [];
        foreach ( $cols as [ $x, $text ] )
            if ( (float)$x >= $xMin && (float)$x < $xMax ) $parts[] = trim( $text );
        return implode( ' ', $parts );
    }

    private function detectCategory( string $line ): ?string {
        if ( preg_match( '/^Біохімічні\s+дослідження/u', $line ) )           return 'Біохімічні';
        if ( preg_match( '/^Гормональні\s+дослідження/u', $line ) )          return 'Гормональні';
        if ( preg_match( '/^Загальноклінічні\s+дослідження/u', $line ) )     return 'Загальноклінічні';
        if ( preg_match( '/^Загальний\s+(аналіз|розгорнутий)/u', $line ) )  return 'Загальний аналіз крові';
        if ( preg_match( '/^Комплекси$/u', $line ) )                         return 'Комплекси';
        return null;
    }

    private function isDateRow( array $cols ): bool {
        foreach ( $cols as [ $x, $text ] )
            if ( (float)$x >= $this->xDateMin && (float)$x <= $this->xDateMax && $this->isDateText( $text ) ) return true;
        if ( count( $cols ) === 1 && $this->isDateText( $cols[0][1] ) ) return true;
        return false;
    }

    private function getDateFromRow( array $cols ): string {
        foreach ( $cols as [ , $text ] ) if ( $this->isDateText( $text ) ) return $text;
        return '';
    }

    private function isDateText( string $s ): bool {
        return (bool) preg_match( '/^\d{2}[.\-]\d{2}[.\-]\d{4}(?:\s+\d{2}:\d{2})?$/', trim( $s ) );
    }

    private function isHintLine( string $line ): bool {
        if ( mb_strlen( $line ) < 3 ) return false;
        if ( preg_match( '/дослідження/u', $line ) ) return false;
        /* Рядок що закінчується кодом у дужках → продовження назви, не hint */
        if ( preg_match( '/\([A-Za-z%#][A-Za-z0-9%#\-\.]{0,20}\)(?:\s*\/\s*\S+)?\s*$/u', $line ) ) return false;
        if ( preg_match( '/^\([^)]{1,30}\)(?:\s*\/\s*\S+)?\s*$/u', $line ) ) return false;
        if ( preg_match( '/;/', $line ) ) return true;
        if ( preg_match( '/^[а-яґєіїa-z]/u', $line ) ) return true;
        if ( preg_match( '/^(?:До\s|Від\s|Більше|Після\s|Значення|Вагітні|Норма\b|Пограничн|Підвищен|Низький|Високий|Дуже\s)/u', $line ) ) return true;
        if ( preg_match( '/\d+[.,]\d*\s*(?:ммоль|мкмоль|нг|мг|МО|мм|нмоль)/u', $line ) ) return true;
        return false;
    }

    private function isSkipLine( string $line ): bool {
        if ( preg_match( '/^_{5,}/', $line ) ) return true;
        return (bool) preg_match(
            '/Медична лабораторія|Атестат про акредитацію|Сторінка\s+\d|©|Ф-ЗД-ЛАБ|acreditat|Завалідовано|csdlab\.ua|РЕЗУЛЬТАТИ ДОСЛІДЖЕНЬ|Результати досліджень не є|Морфологіч|нормохромн|нормоцитар|\*Результат|зареєстрован|Загальний розгорнутий аналіз крові/u',
            $line
        );
    }

    private function isNumericValue( string $val ): bool {
        $clean = preg_replace( '/^[><]/', '', trim( $val ) );
        return is_numeric( str_replace( ',', '.', $clean ) );
    }

    private function parseRange( string $ref ): array {
        if ( preg_match( '/([\d.,]+)\s*-\s*([\d.,]+)/', $ref, $m ) )
            return [ (float) str_replace( ',', '.', $m[1] ), (float) str_replace( ',', '.', $m[2] ) ];
        return [ 0.0, 0.0 ];
    }

    private function parseDate( string $s ): ?int {
        if ( preg_match( '/(\d{2})[.\-](\d{2})[.\-](\d{4})(?:[T\s]+(\d{2}):(\d{2}))?/', $s, $m ) ) {
            $dt = sprintf( '%s-%s-%s %s:%s:00', $m[3],$m[2],$m[1],$m[4]??'00',$m[5]??'00' );
            $ts = strtotime( $dt );
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    /* ════════════════════════════════════════════════════════
       ЗБЕРЕЖЕННЯ В БД
       ════════════════════════════════════════════════════════ */

    private function saveIndicator( array $d ): ?int {
        $db = $this->db;
        if ( ! $d['name'] || $d['name'] === 'Unknown' ) return null;

        /* Пошук тільки по name */
        $id = $db->get_var( $db->prepare(
            "SELECT id FROM {$this->t_ind} WHERE name=%s LIMIT 1", $d['name']
        ) );
        if ( $id ) {
            /* Оновлюємо hint якщо є новий */
            if ( $d['interpretation_hint'] ) {
                $db->update( $this->t_ind,
                    [ 'interpretation_hint' => sanitize_text_field( mb_substr( $d['interpretation_hint'], 0, 2000 ) ) ],
                    [ 'id' => $id ]
                );
            }
            return (int) $id;
        }

        $ok = $db->insert( $this->t_ind, [
            'name'                => $d['name'],
            'min'                 => $d['min'],
            'max'                 => $d['max'],
            'measure'             => $d['measure'] ?: null,
            'category'            => $d['category'] ?: null,
            'interpretation_hint' => $d['interpretation_hint']
                ? sanitize_text_field( mb_substr( $d['interpretation_hint'], 0, 2000 ) )
                : null,
        ], [ '%s','%f','%f','%s','%s','%s' ] );

        if ( false === $ok ) {
            if ( preg_match( '/Duplicate/', (string) $db->last_error ) ) {
                $id = $db->get_var( $db->prepare(
                    "SELECT id FROM {$this->t_ind} WHERE name=%s LIMIT 1", $d['name']
                ) );
                if ( $id ) return (int) $id;
            }
            error_log( '[MedStat] indicator: ' . $db->last_error );
            return null;
        }
        return (int) $db->insert_id;
    }

    private function saveMeasurement( array $d ): ?int {
        $ok = $this->db->insert( $this->t_meas, [
            'id_order'       => $d['id_order'],
            'id_indicator'   => $d['id_indicator'],
            'result_value'   => $d['result_value'],
            'execution_date' => $d['execution_date'],
            'is_normal'      => $d['is_normal'],
        ], [ '%d','%d','%s','%s','%d' ] );
        if ( false === $ok ) { error_log( '[MedStat] measurement: ' . $this->db->last_error ); return null; }
        return (int) $this->db->insert_id;
    }
}
