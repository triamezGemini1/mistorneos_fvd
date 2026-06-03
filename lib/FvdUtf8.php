<?php

declare(strict_types=1);

/**
 * Reparación de textos con mojibake (Latin-1/Windows-1252 mal interpretado como UTF-8).
 */
final class FvdUtf8
{
    private const REPL = "\xEF\xBF\xBD";

    /**
     * Repara una cadena con corrupción típica de encoding en español.
     */
    public static function repair(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = self::repairTripleReplacementDisplay($text);
        $text = self::repairSplitUtf8Mojibake($text);
        $text = self::repairLatin1Mojibake($text);
        $text = self::repairReplacementChar($text);
        $text = self::repairBrokenMarkers($text);
        $text = self::repairMiscSequences($text);

        return $text;
    }

    /**
     * U+FFFD mostrado como tres caracteres Latin-1 (ï¿½ → byte de reemplazo UTF-8).
     */
    public static function repairTripleReplacementDisplay(string $text): string
    {
        if (strpos($text, 'ï¿½') === false) {
            return $text;
        }

        return str_replace('ï¿½', self::REPL, $text);
    }

    /**
     * Secuencia UTF-8 de 2 bytes partida en Ã + carácter Latin-1/CP1252 (ej. Ã³ → ó, Ã" → Ó).
     */
    public static function repairSplitUtf8Mojibake(string $text): string
    {
        if (strpos($text, 'Ã') === false && strpos($text, 'Â') === false) {
            return $text;
        }

        $text = preg_replace_callback('/Ã(.)/u', static function (array $m): string {
            $trail = self::cp1252CharToByte($m[1]);
            if ($trail === null) {
                $cp = mb_ord($m[1], 'UTF-8');
                if ($cp !== false && $cp >= 0x80 && $cp <= 0xFF) {
                    $trail = $cp;
                }
            }
            if ($trail === null) {
                return $m[0];
            }
            $bytes = chr(0xC3) . chr($trail);
            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $m[0];
        }, $text) ?? $text;

        $text = preg_replace_callback('/Â(.)/u', static function (array $m): string {
            $trail = self::cp1252CharToByte($m[1]);
            if ($trail === null) {
                $cp = mb_ord($m[1], 'UTF-8');
                if ($cp !== false && $cp >= 0x80 && $cp <= 0xBF) {
                    $trail = $cp;
                }
            }
            if ($trail === null || $trail > 0xBF) {
                return $m[0];
            }
            $bytes = chr(0xC2) . chr($trail);
            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $m[0];
        }, $text) ?? $text;

        return $text;
    }

    /**
     * Carácter Unicode típico de mojibake CP1252 → byte original (0x80-0xFF).
     */
    private static function cp1252CharToByte(string $char): ?int
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            for ($b = 0x80; $b <= 0xFF; $b++) {
                $utf8 = @iconv('CP1252', 'UTF-8//IGNORE', chr($b));
                if ($utf8 !== false && $utf8 !== '') {
                    $map[$utf8] = $b;
                }
            }
        }

        return $map[$char] ?? null;
    }

    /**
     * UTF-8 interpretado como ISO-8859-1 y guardado otra vez (doble codificación clásica).
     */
    public static function repairLatin1Mojibake(string $text): string
    {
        if (!preg_match('/(?:Ã.|Â.|â€.|â†.)/u', $text)) {
            return $text;
        }

        $fixed = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        if ($fixed === false || $fixed === '') {
            return $text;
        }

        $scoreBefore = self::mojibakeScore($text);
        $scoreAfter = self::mojibakeScore($fixed);

        return $scoreAfter < $scoreBefore ? $fixed : $text;
    }

    private static function mojibakeScore(string $text): int
    {
        return preg_match_all('/Ã/u', $text)
            + preg_match_all('/â€/u', $text)
            + preg_match_all('/Â/u', $text)
            + preg_match_all('/' . preg_quote(self::REPL, '/') . '/u', $text);
    }

    public static function repairReplacementChar(string $text): string
    {
        if (strpos($text, self::REPL) === false) {
            return $text;
        }

        $r = preg_quote(self::REPL, '/');
        $map = [
            "/{$r}Desea/u" => '¿Desea',
            "/{$r}Est{$r} seguro/u" => '¿Está seguro',
            "/{$r}Est{$r}/u" => '¿Está',
            "/{$r} Cada/u" => '• Cada',
            "/{$r} Los/u" => '• Los',
            '/Informaci' . $r . 'n/u' => 'Información',
            '/informaci' . $r . 'n/u' => 'información',
            '/Informaci' . $r . 'n/u' => 'Información',
            '/Administraci' . $r . 'n/u' => 'Administración',
            '/administraci' . $r . 'n/u' => 'administración',
            '/Organizaci' . $r . 'n/u' => 'Organización',
            '/organizaci' . $r . 'n/u' => 'organización',
            '/Exportaci' . $r . 'n/u' => 'Exportación',
            '/exportaci' . $r . 'n/u' => 'exportación',
            '/Paginaci' . $r . 'n/u' => 'Paginación',
            '/paginaci' . $r . 'n/u' => 'paginación',
            '/Eliminaci' . $r . 'n/u' => 'Eliminación',
            '/eliminaci' . $r . 'n/u' => 'eliminación',
            '/Numeraci' . $r . 'n/u' => 'Numeración',
            '/numeraci' . $r . 'n/u' => 'numeración',
            '/validaci' . $r . 'n/u' => 'validación',
            '/Validaci' . $r . 'n/u' => 'Validación',
            '/conexi' . $r . 'n/u' => 'conexión',
            '/selecci' . $r . 'n/u' => 'selección',
            '/Selecci' . $r . 'n/u' => 'Selección',
            '/edici' . $r . 'n/u' => 'edición',
            '/Edici' . $r . 'n/u' => 'Edición',
            '/Funci' . $r . 'n/u' => 'Función',
            '/funci' . $r . 'n/u' => 'función',
            '/acci' . $r . 'n/u' => 'acción',
            '/Acci' . $r . 'n/u' => 'Acción',
            '/Par' . $r . 'metros/u' => 'Parámetros',
            '/par' . $r . 'metros/u' => 'parámetros',
            '/Estad' . $r . 'sticas/u' => 'Estadísticas',
            '/estad' . $r . 'sticas/u' => 'estadísticas',
            '/B' . $r . 'squeda/u' => 'Búsqueda',
            '/b' . $r . 'squeda/u' => 'búsqueda',
            '/n' . $r . 'meros/u' => 'números',
            '/N' . $r . 'mero/u' => 'Número',
            '/Categor' . $r . 'a/u' => 'Categoría',
            '/categor' . $r . 'a/u' => 'categoría',
            '/Contrase' . $r . 'a/u' => 'Contraseña',
            '/contrase' . $r . 'a/u' => 'contraseña',
            '/seg' . $r . 'n/u' => 'según',
            '/espec' . $r . 'fico/u' => 'específico',
            '/espec' . $r . 'fica/u' => 'específica',
            '/m' . $r . 'ltiples/u' => 'múltiples',
            '/vac' . $r . 'o/u' => 'vacío',
            '/bot' . $r . 'n/u' => 'botón',
            '/M' . $r . 'nimo/u' => 'Mínimo',
            '/m' . $r . 'nimo/u' => 'mínimo',
            '/a' . $r . 'os/u' => 'años',
            '/alfab' . $r . 'ticamente/u' => 'alfabéticamente',
            '/despu' . $r . 's/u' => 'después',
            '/autom' . $r . 'ticamente/u' => 'automáticamente',
            '/est' . $r . ' marcada/u' => 'está marcada',
            '/est' . $r . ' aplicando/u' => 'está aplicando',
            '/est' . $r . ' listo/u' => 'está listo',
            '/pas' . $r . '/u' => 'pasó',
            '/podr' . $r . '/u' => 'podrá',
            '/mostrar' . $r . '/u' => 'mostrará',
            '/actualizar' . $r . '/u' => 'actualizará',
            '/aplicar' . $r . '/u' => 'aplicará',
            '/numerar' . $r . '/u' => 'numerará',
            '/ordenar' . $r . '/u' => 'ordenará',
            '/procesar' . $r . '/u' => 'procesará',
            '/tendr' . $r . '/u' => 'tendrá',
            '/necesitar' . $r . 'amos/u' => 'necesitaremos',
            '/inv' . $r . 'lido/u' => 'inválido',
            '/Inv' . $r . 'lido/u' => 'Inválido',
            '/inv' . $r . 'lida/u' => 'inválida',
            '/m' . $r . 'todo/u' => 'método',
            '/M' . $r . 'dulo/u' => 'Módulo',
            '/m' . $r . 'dulo/u' => 'módulo',
            '/gesti' . $r . 'n/u' => 'gestión',
            '/Gesti' . $r . 'n/u' => 'Gestión',
            '/b' . $r . 'sica/u' => 'básica',
            '/B' . $r . 'sica/u' => 'Básica',
            '/cuadr' . $r . 'cula/u' => 'cuadrícula',
            '/Cuadr' . $r . 'cula/u' => 'Cuadrícula',
            '/anotaci' . $r . 'n/u' => 'anotación',
            '/tel' . $r . 'fono/u' => 'teléfono',
            '/Tel' . $r . 'fono/u' => 'Teléfono',
            '/C' . $r . 'dula/u' => 'Cédula',
            '/c' . $r . 'dula/u' => 'cédula',
            '/l' . $r . 'gico/u' => 'lógico',
            '/g' . $r . 'nero/u' => 'género',
            '/G' . $r . 'nero/u' => 'Género',
            '/C' . $r . 'mo/u' => 'Cómo',
            '/c' . $r . 'mo/u' => 'cómo',
            '/' . $r . 'ltima/u' => 'última',
            '/' . $r . 'ltimo/u' => 'último',
            '/' . $r . 'nica/u' => 'única',
            '/' . $r . 'nico/u' => 'único',
            '/' . $r . 'ltima mesa/u' => 'última mesa',
            '/sesi' . $r . 'n/u' => 'sesión',
            '/Sesi' . $r . 'n/u' => 'Sesión',
            '/Vinculaci' . $r . 'n/u' => 'Vinculación',
            '/recibi' . $r . '/u' => 'recibió',
            '/Recibi' . $r . '/u' => 'Recibió',
            '/' . $r . 'xito/u' => 'éxito',
            '/' . $r . 'XITO/u' => 'ÉXITO',
            '/ci' . $r . 'n/u' => 'ción',
            '/Invitaci' . $r . 'n/u' => 'Invitación',
            '/invitaci' . $r . 'n/u' => 'invitación',
            '/INSCRIPCI' . $r . 'N/u' => 'INSCRIPCIÓN',
            '/INVITACI' . $r . 'N/u' => 'INVITACIÓN',
            '/INFORMACI' . $r . 'N/u' => 'INFORMACIÓN',
            '/asignaci' . $r . 'n/u' => 'asignación',
            '/recibir' . $r . ' la/u' => 'recibirá la',
            '/recalcular' . $r . ' la/u' => 'recalculará la',
            '/generar' . $r . ' /u' => 'generará ',
            '/generar' . $r . ' un/u' => 'generará un',
            '/calcular' . $r . ' autom/u' => 'calculará autom',
            '/p' . $r . 'gina/u' => 'página',
            '/P' . $r . 'gina/u' => 'Página',
            '/v' . $r . 'lido/u' => 'válido',
            '/V' . $r . 'lido/u' => 'Válido',
            '/finaliz' . $r . '/u' => 'finalizó',
            '/abri' . $r . '/u' => 'abrió',
            '/�rea/u' => 'Área',
            '/d' . $r . 'as/u' => 'días',
            '/d' . $r . 'a antes/u' => 'día antes',
            '/extra' . $r . 'da/u' => 'extraída',
            '/vac' . $r . 'a/u' => 'vacía',
            '/expl' . $r . 'citamente/u' => 'explícitamente',
            '/est' . $r . ' en/u' => 'está en',
            '/necesitar' . $r . ' cada/u' => 'necesitará cada',
            '/necesitar' . $r . ' /u' => 'necesitará ',
            '/n' . $r . 'mero/u' => 'número',
            '/N' . $r . 'mero/u' => 'Número',
            '/c' . $r . 'digo/u' => 'código',
            '/C' . $r . 'digo/u' => 'Código',
            '/pa' . $r . 's/u' => 'país',
            '/Pa' . $r . 's/u' => 'País',
            '/env' . $r . 'o/u' => 'envío',
            '/Env' . $r . 'o/u' => 'Envío',
            '/Env' . $r . 'elo/u' => 'Envíelo',
            '/env' . $r . 'elo/u' => 'envíelo',
            '/complet' . $r . '/u' => 'completó',
            '/Pr' . $r . 'ximos/u' => 'Próximos',
            '/pr' . $r . 'ximos/u' => 'próximos',
            '/mediod' . $r . 'a/u' => 'mediodía',
            '/DOMIN' . $r . '/u' => 'DOMINÓ',
            '/PER' . $r . 'ODO/u' => 'PERÍODO',
            '/AUTOM' . $r . 'TICO/u' => 'AUTOMÁTICO',
            '/aqu' . $r . '/u' => 'aquí',
            '/Aqu' . $r . '/u' => 'Aquí',
            '/M' . $r . 's /u' => 'Más ',
            '/participaci' . $r . 'n/u' => 'participación',
            '/Participaci' . $r . 'n/u' => 'Participación',
            '/t' . $r . 'pico/u' => 'típico',
            '/simul' . $r . '/u' => 'simuló',
            '/' . $r . 'INFORMACI/u' => '¡INFORMACI',
            '/' . $r . 'Esperamos/u' => '¡Esperamos',
            '/' . $r . 'Invitaci/u' => '¡Invitaci',
            '/est' . $r . ' OK/u' => 'está OK',
            '/D' . $r . 'lares/u' => 'Dólares',
            '/d' . $r . 'lares/u' => 'dólares',
            '/Bol' . $r . 'vares/u' => 'Bolívares',
            '/bol' . $r . 'vares/u' => 'bolívares',
            '/M' . $r . 'vil/u' => 'Móvil',
            '/m' . $r . 'vil/u' => 'móvil',
            '/autom' . $r . 'tica/u' => 'automática',
            '/autom' . $r . 'tico/u' => 'automático',
            '/problem' . $r . 'ticos/u' => 'problemáticos',
            '/Bot' . $r . 'n/u' => 'Botón',
            '/bot' . $r . 'n/u' => 'botón',
            '/abri' . $r . 'ndose/u' => 'abriéndose',
            '/raz' . $r . 'n /u' => 'razón ',
            '/m' . $r . 's /u' => 'más ',
            '/r' . $r . 'pido/u' => 'rápido',
            '/SECCI' . $r . 'N/u' => 'SECCIÓN',
            '/M' . $r . 'S /u' => 'MÁS ',
            '/A' . $r . 'dir/u' => 'Añadir',
            '/ENV' . $r . 'O /u' => 'ENVÍO ',
            '/pesta' . $r . 'a/u' => 'pestaña',
            '/F' . $r . 'cil/u' => 'Fácil',
            '/f' . $r . 'cil/u' => 'fácil',
            '/Versi' . $r . 'n/u' => 'Versión',
            '/versi' . $r . 'n/u' => 'versión',
            '/Aseg' . $r . 'rese/u' => 'Asegúrese',
            '/CR' . $r . 'TICO/u' => 'CRÍTICO',
            '/cr' . $r . 'tico/u' => 'crítico',
            '/DESPU' . $r . 'S /u' => 'DESPUÉS ',
            '/' . $r . 'WhatsApp/u' => '¡WhatsApp',
        ];

        foreach ($map as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Restaura viñetas/advertencias dañadas (?? → • / ⚠ / ✓) sin tocar el operador PHP ??.
     */
    public static function repairBrokenMarkers(string $text): string
    {
        if (strpos($text, '??') === false && strpos($text, '? ') === false && strpos($text, self::REPL . ' ') !== false) {
            // puede haber solo bullets con REPL
        } elseif (strpos($text, '??') === false && strpos($text, '? ') === false && strpos($text, self::REPL . ' ') === false) {
            return $text;
        }

        $text = preg_replace("/(?<=[\'\"])\\?\\? /u", '• ', $text) ?? $text;
        $text = str_replace(
            ["\n?? Esta acción", '\n?? Esta acción', "\n?? ¡", '\n?? ¡', '?? ?? ??', 'est• OK', '*?? INVITACI', '`?? *', '2?? Acceso'],
            ["\n⚠ Esta acción", '\n⚠ Esta acción', "\n⚠ ¡", '\n⚠ ¡', '⚠ ⚠ ⚠', 'está OK', '*📋 INVITACI', '`*📋 ', '2️⃣ Acceso'],
            $text
        );
        $text = preg_replace("/alert\\('\\? /u", "alert('✓ ", $text) ?? $text;
        $text = preg_replace("/alert\\('\\? Error/u", "alert('✗ Error", $text) ?? $text;
        $text = preg_replace("/\\+ '\\? Error/u", "+ '✗ Error", $text) ?? $text;
        $text = preg_replace("/detalleMsg \\+= '\\? /u", "detalleMsg += '✓ ", $text) ?? $text;
        $text = preg_replace("/alert\\('\\? Por favor/u", "alert('⚠ Por favor", $text) ?? $text;
        $text = str_replace(self::REPL . ' ', '• ', $text);

        return $text;
    }

    public static function repairMiscSequences(string $text): string
    {
        $map = [
            'â€"' => "\u{2014}",
            'â€"' => "\u{2013}",
            'â€˜' => "\u{2018}",
            'â€™' => "\u{2019}",
            'â€œ' => "\u{201C}",
            'â€' => "\u{201D}",
            'â†' => "\u{2192}",
            'â€¦' => "\u{2026}",
        ];

        return str_replace(array_keys($map), array_values($map), $text);
    }

    /**
     * @return list<string>
     */
    public static function defaultScanExtensions(): array
    {
        return ['php', 'js', 'css', 'html', 'htm', 'vue', 'md'];
    }

    /**
     * @return list<string>
     */
    public static function defaultScanDirs(string $root): array
    {
        return [
            $root . DIRECTORY_SEPARATOR . 'modules',
            $root . DIRECTORY_SEPARATOR . 'public',
            $root . DIRECTORY_SEPARATOR . 'lib',
            $root . DIRECTORY_SEPARATOR . 'resources',
            $root . DIRECTORY_SEPARATOR . 'includes',
            $root . DIRECTORY_SEPARATOR . 'config',
            $root . DIRECTORY_SEPARATOR . 'desktop',
        ];
    }
}
