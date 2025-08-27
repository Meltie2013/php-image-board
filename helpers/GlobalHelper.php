<?php

class GlobalHelper
{
    /**
     * Format file size to human readable form.
     *
     * @param int $bytes
     * @param int $decimals
     * @return string
     */
    public static function formatFileSize(int $bytes, int $decimals = 2): string
    {
        if ($bytes < 0)
        {
            return '0 B';
        }

        $sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $sizes[$factor]);
    }
}
