<?php

declare(strict_types=1);

namespace Modules\Shop\CourierCsv;

/**
 * Checks CSV content for encoding issues (non-UTF-8 bytes, BOM, invalid characters).
 */
final class CourierCsvEncodingChecker
{
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    private const BOM_UTF16_LE = "\xFF\xFE";

    private const BOM_UTF16_BE = "\xFE\xFF";

    /**
     * Check a CSV string for encoding problems.
     *
     * @return array{
     *     valid: bool,
     *     encoding: string,
     *     has_bom: bool,
     *     bom_type: string|null,
     *     issues: array<int, string>,
     *     byte_length: int,
     *     char_length: int,
     * }
     */
    public function check(string $csvContent): array
    {
        $issues = [];
        $hasBom = false;
        $bomType = null;

        $bom = $this->detectBom($csvContent);
        if ($bom !== null) {
            $hasBom = true;
            $bomType = $bom;
            $issues[] = "File starts with a {$bom} BOM — courier systems may reject this.";
        }

        $stripped = $this->stripBom($csvContent);

        $detected = mb_detect_encoding($stripped, ['UTF-8', 'ASCII', 'ISO-8859-1', 'Windows-1252'], true);

        if ($detected === false) {
            $detected = 'Unknown';
            $issues[] = 'Unable to detect encoding — file may contain mixed or binary data.';
        } elseif ($detected !== 'UTF-8' && $detected !== 'ASCII') {
            $issues[] = "Detected encoding is {$detected}, expected UTF-8.";
        }

        if ($detected === 'UTF-8' || $detected === 'ASCII') {
            $invalidPositions = $this->findInvalidUtf8($stripped);
            if ($invalidPositions !== []) {
                $count = count($invalidPositions);
                $issues[] = "Found {$count} invalid UTF-8 byte sequence(s) at byte offset(s): ".implode(', ', array_slice($invalidPositions, 0, 10)).($count > 10 ? ' …' : '');
            }
        }

        $nullBytes = substr_count($stripped, "\x00");
        if ($nullBytes > 0) {
            $issues[] = "Found {$nullBytes} null byte(s) — file may be binary or UTF-16 encoded.";
        }

        $charLength = mb_strlen($stripped, 'UTF-8') ?: strlen($stripped);

        return [
            'valid' => $issues === [],
            'encoding' => $detected,
            'has_bom' => $hasBom,
            'bom_type' => $bomType,
            'issues' => $issues,
            'byte_length' => strlen($csvContent),
            'char_length' => $charLength,
        ];
    }

    /**
     * Convert a CSV string to clean UTF-8 without BOM.
     */
    public function normalize(string $csvContent): string
    {
        $content = $this->stripBom($csvContent);

        $detected = mb_detect_encoding($content, ['UTF-8', 'ASCII', 'ISO-8859-1', 'Windows-1252'], true);

        if ($detected !== false && $detected !== 'UTF-8' && $detected !== 'ASCII') {
            $converted = @mb_convert_encoding($content, 'UTF-8', $detected);
            if ($converted !== false) {
                $content = $converted;
            }
        }

        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content) ?? $content;

        return $content;
    }

    /**
     * Check a file path for encoding issues.
     *
     * @return array<string, mixed>
     */
    public function checkFile(string $path): array
    {
        if (! file_exists($path)) {
            return [
                'valid' => false,
                'encoding' => 'Unknown',
                'has_bom' => false,
                'bom_type' => null,
                'issues' => ['File does not exist.'],
                'byte_length' => 0,
                'char_length' => 0,
            ];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return [
                'valid' => false,
                'encoding' => 'Unknown',
                'has_bom' => false,
                'bom_type' => null,
                'issues' => ['Unable to read file.'],
                'byte_length' => 0,
                'char_length' => 0,
            ];
        }

        return $this->check($content);
    }

    private function detectBom(string $content): ?string
    {
        if (str_starts_with($content, self::BOM_UTF8)) {
            return 'UTF-8';
        }

        if (str_starts_with($content, self::BOM_UTF16_LE)) {
            return 'UTF-16LE';
        }

        if (str_starts_with($content, self::BOM_UTF16_BE)) {
            return 'UTF-16BE';
        }

        return null;
    }

    private function stripBom(string $content): string
    {
        foreach ([self::BOM_UTF8, self::BOM_UTF16_LE, self::BOM_UTF16_BE] as $bom) {
            if (str_starts_with($content, $bom)) {
                return substr($content, strlen($bom));
            }
        }

        return $content;
    }

    /**
     * @return array<int, int>
     */
    private function findInvalidUtf8(string $content): array
    {
        $positions = [];
        $offset = 0;
        $len = strlen($content);

        while ($offset < $len) {
            $byte = ord($content[$offset]);

            if ($byte <= 0x7F) {
                $offset++;

                continue;
            }

            if ($byte >= 0xC2 && $byte <= 0xDF) {
                $seqLen = 2;
            } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                $seqLen = 3;
            } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                $seqLen = 4;
            } else {
                $positions[] = $offset;
                $offset++;

                continue;
            }

            if ($offset + $seqLen > $len) {
                $positions[] = $offset;
                $offset++;

                continue;
            }

            $valid = true;
            for ($i = 1; $i < $seqLen; $i++) {
                $next = ord($content[$offset + $i]);
                if ($next < 0x80 || $next > 0xBF) {
                    $valid = false;
                    break;
                }
            }

            if (! $valid) {
                $positions[] = $offset;
                $offset++;
            } else {
                $offset += $seqLen;
            }
        }

        return $positions;
    }
}
