<?php

/**
 * HashingHelper
 *
 * Utility class for generating and comparing image hashes.
 *
 * Supported hashing algorithms:
 * - aHash (Average Hash): Measures average brightness across an image and creates a binary hash.
 * - pHash (Perceptual Hash): Uses frequency domain analysis (DCT) to detect structural similarity.
 * - dHash (Difference Hash): Encodes relative changes in brightness between adjacent pixels.
 *
 * Purpose:
 * - Provides consistent methods to hash images and compare their similarity.
 * - Helps detect duplicate or near-duplicate images regardless of format, resolution, or minor edits.
 * - Can be extended to normalize images (resize, grayscale, transparency removal) before hashing
 *   to improve reliability across formats (e.g., PNG vs JPG).
 *
 * Usage:
 * - Generate a hash for each image with the desired algorithm.
 * - Compare hashes using Hamming distance to determine similarity.
 *
 * Note:
 * - For best results, normalize images before hashing (e.g., convert all to JPG, resize, and grayscale).
 * - Lower Hamming distance = more similar images.
 */

class HashingHelper
{
    /**
     * Perform a 2D Discrete Cosine Transform (DCT-II) on a square matrix.
     * Used in pHash to extract low-frequency image features.
     *
     * @param array $matrix Input NxN grayscale values.
     * @return array Transformed NxN matrix with frequency coefficients.
     */
    public static function dct2($matrix)
    {
        $N = count($matrix);   // Size of the NxN matrix
        $dct = [];                    // Output DCT coefficients
        for ($u = 0; $u < $N; $u++)   // Loop over output row index
        {
            for ($v = 0; $v < $N; $v++) // Loop over output column index
            {
                $sum = 0;
                // Compute weighted sum for coefficient (u, v)
                for ($i = 0; $i < $N; $i++)   // Loop over input rows
                {
                    for ($j = 0; $j < $N; $j++) // Loop over input columns
                    {
                        // Apply DCT-II formula with cosine weightings
                        $sum += $matrix[$i][$j] *
                            cos((2 * $i + 1) * $u * M_PI / (2 * $N)) *
                            cos((2 * $j + 1) * $v * M_PI / (2 * $N));
                    }
                }

                // Normalization factors to maintain orthogonality
                $cu = ($u === 0) ? 1 / sqrt(2) : 1;
                $cv = ($v === 0) ? 1 / sqrt(2) : 1;

                // Final coefficient value
                $dct[$u][$v] = 0.25 * $cu * $cv * $sum;
            }
        }

        return $dct;
    }

    /**
     * Compute the median of a numeric array.
     * Sorting ensures correct median selection.
     *
     * @param array $arr Input array of numbers.
     * @return float|int Median value.
     */
    public static function median($arr)
    {
        sort($arr);                   // Sort values ascending
        $c = count($arr);             // Count total elements
        $mid = (int)($c / 2);         // Middle index

        // If even number of elements → take average of two middle values
        // If odd → take exact middle element
        return ($c % 2 === 0) ? ($arr[$mid - 1] + $arr[$mid]) / 2 : $arr[$mid];
    }

    /**
     * Convert a binary string into a hexadecimal string.
     * Ensures consistent length: always 64 hex chars (256 bits).
     *
     * @param string $bits Binary string (e.g., "1010...")
     * @return string Hexadecimal representation.
     */
    public static function bitsToHex($bits)
    {
        // Pad binary string length to a multiple of 4 bits (nibble alignment)
        $padLength = (4 - (strlen($bits) % 4)) % 4;
        if ($padLength > 0)
        {
            $bits = str_pad($bits, strlen($bits) + $padLength, '0', STR_PAD_RIGHT);
        }

        $hex = '';
        // Process binary string 4 bits at a time
        for ($i = 0; $i < strlen($bits); $i += 4)
        {
            $chunk = substr($bits, $i, 4);       // Extract nibble
            $hex .= dechex(bindec($chunk));      // Convert binary → decimal → hex
        }

        // Pad/truncate to exactly 64 hex characters (256 bits)
        return str_pad($hex, 64, '0', STR_PAD_RIGHT);
    }

    /**
     * Convert a hex string into a binary string of fixed length.
     * Used when analyzing or comparing hashes at bit level.
     *
     * @param string $hex Hexadecimal input.
     * @param int $bitLength Output binary string length (default: 256 bits).
     * @return string Binary representation.
     */
    public static function hexToBinary(string $hex, int $bitLength = 256): string
    {
        $bin = base_convert($hex, 16, 2);        // Convert hex → binary string
        return str_pad($bin, $bitLength, '0', STR_PAD_LEFT); // Pad to fixed length
    }

    /**
     * Compute Hamming distance between two hex hashes.
     * Hamming distance = number of differing bits.
     *
     * @param string $hash1 First hex hash.
     * @param string $hash2 Second hex hash.
     * @return int Number of differing bits.
     */
    public static function hammingDistance(string $hash1, string $hash2): int
    {
        $bin1 = hex2bin($hash1);       // Convert hex → raw binary
        $bin2 = hex2bin($hash2);

        // Ensure both binary strings are equal length
        $maxLen = max(strlen($bin1), strlen($bin2));
        $bin1 = str_pad($bin1, $maxLen, "\0", STR_PAD_LEFT);
        $bin2 = str_pad($bin2, $maxLen, "\0", STR_PAD_LEFT);

        $distance = 0;
        for ($i = 0; $i < $maxLen; $i++)
        {
            // XOR each byte → differing bits become 1
            $xor = ord($bin1[$i]) ^ ord($bin2[$i]);

            // Count 1s in binary representation of XOR result
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    /**
     * Compute Average Hash (aHash) of an image.
     * - Resize → NxN
     * - Convert pixels → grayscale
     * - Compare each pixel against average brightness
     * - Generate 256-bit hash
     *
     * @param string $filePath Path to image file.
     * @param int $size Resize dimension (default: 16x16).
     * @return string 256-bit hash in hex.
     */
    public static function aHash($filePath, $size = 16): string
    {
        $img = imagecreatefromstring(file_get_contents($filePath)); // Load image
        $resized = imagecreatetruecolor($size, $size);               // Create resized canvas
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $size, $size, imagesx($img), imagesy($img));
        imagedestroy($img); // Free memory

        $grayValues = [];
        // Convert image into grayscale array
        for ($y = 0; $y < $size; $y++)
        {
            for ($x = 0; $x < $size; $x++)
            {
                $rgb = imagecolorat($resized, $x, $y); // Pixel color
                $r = ($rgb >> 16) & 0xFF;                           // Extract red
                $g = ($rgb >> 8) & 0xFF;                            // Extract green
                $b = $rgb & 0xFF;                                   // Extract blue
                $gray = ($r * 0.299 + $g * 0.587 + $b * 0.114);     // Luminance formula
                $grayValues[] = $gray;                              // Store grayscale value
            }
        }

        imagedestroy($resized);

        // Compute average grayscale brightness
        $avg = array_sum($grayValues) / count($grayValues);
        $bits = '';
        foreach ($grayValues as $gray)
        {
            // Assign 1 if pixel ≥ average, else 0
            $bits .= ($gray >= $avg) ? '1' : '0';
        }

        // Extend/repeat bit string until 256 bits
        while (strlen($bits) < 256)
        {
            $bits .= $bits;
        }

        $bits = substr($bits, 0, 256); // Trim to exact length

        return self::bitsToHex($bits);
    }

    /**
     * Compute Difference Hash (dHash) of an image.
     * - Resize → WxH
     * - Compare each pixel with its right neighbor
     * - Generate 256-bit hash
     *
     * @param string $filePath Path to image file.
     * @param int $width Resize width (default: 17).
     * @param int $height Resize height (default: 16).
     * @return string 256-bit hash in hex.
     */
    public static function dHash($filePath, $width = 17, $height = 16): string
    {
        $img = imagecreatefromstring(file_get_contents($filePath)); // Load image
        $resized = imagecreatetruecolor($width, $height);            // Create resized canvas
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $width, $height, imagesx($img), imagesy($img));
        imagedestroy($img); // Free memory

        $bits = '';
        // Compare each pixel with the one immediately to its right
        for ($y = 0; $y < $height; $y++)
        {
            for ($x = 0; $x < $width - 1; $x++)
            {
                $left = imagecolorat($resized, $x, $y);       // Current pixel
                $right = imagecolorat($resized, $x + 1, $y);  // Neighbor pixel

                // Convert both pixels to grayscale
                $lGray = (($left >> 16 & 0xFF) * 0.299 + (($left >> 8) & 0xFF) * 0.587 + ($left & 0xFF) * 0.114);
                $rGray = (($right >> 16 & 0xFF) * 0.299 + (($right >> 8) & 0xFF) * 0.587 + ($right & 0xFF) * 0.114);

                // Assign 1 if left brighter, else 0
                $bits .= ($lGray > $rGray) ? '1' : '0';
            }
        }

        // Repeat or trim to 256 bits
        while (strlen($bits) < 256)
        {
            $bits .= $bits;
        }

        $bits = substr($bits, 0, 256);

        return self::bitsToHex($bits);
    }

    /**
     * Compute Perceptual Hash (pHash) of an image.
     * - Resize → NxN
     * - Convert pixels → grayscale
     * - Apply 2D DCT transform
     * - Keep top-left (low-frequency) coefficients
     * - Compare coefficients to median
     * - Generate 256-bit hash
     *
     * @param string $filePath Path to image file.
     * @param int $size Resize size (default: 32x32).
     * @param int $smallSize DCT low-frequency region size (default: 16x16).
     * @return string 256-bit hash in hex.
     */
    public static function pHash($filePath, $size = 32, $smallSize = 16): string
    {
        $img = imagecreatefromstring(file_get_contents($filePath)); // Load image
        $resized = imagecreatetruecolor($size, $size);               // Create resized canvas
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $size, $size, imagesx($img), imagesy($img));
        imagedestroy($img); // Free memory

        $matrix = [];
        // Convert image into grayscale matrix
        for ($y = 0; $y < $size; $y++)
        {
            for ($x = 0; $x < $size; $x++)
            {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $matrix[$y][$x] = ($r * 0.299 + $g * 0.587 + $b * 0.114);
            }
        }

        // Apply 2D DCT transform to grayscale matrix
        $dct = self::dct2($matrix);

        // Select top-left low-frequency coefficients
        $dctLow = [];
        for ($y = 0; $y < $smallSize; $y++)
        {
            for ($x = 0; $x < $smallSize; $x++)
            {
                $dctLow[] = $dct[$y][$x];
            }
        }

        // Compute median of coefficients
        $median = self::median($dctLow);
        $bits = '';
        foreach ($dctLow as $v)
        {
            // Assign 1 if coefficient > median, else 0
            $bits .= ($v > $median) ? '1' : '0';
        }

        // Repeat/trim to 256 bits
        while (strlen($bits) < 256)
        {
            $bits .= $bits;
        }

        $bits = substr($bits, 0, 256);

        return self::bitsToHex($bits);
    }

    /**
     * Compute Block-based Perceptual Hash (structural pHash) of an image.
     * - Divides image into NxN blocks
     * - Applies simple edge detection to emphasize structure
     * - Computes pHash per block
     * - Returns array of 256-bit hex hashes for each block
     *
     * @param string $filePath Path to image file.
     * @param int $blocks Number of blocks per row/column (default: 4 → 16 blocks total)
     * @param int $size Resize size for each block (default: 32x32)
     * @param int $smallSize DCT low-frequency region size (default: 16x16)
     * @return array Array of block hashes (hex strings)
     */
    public static function blockPHash($filePath, $blocks = 4, $size = 32, $smallSize = 16): array
    {
        $img = imagecreatefromstring(file_get_contents($filePath)); // Load image
        $width = imagesx($img);
        $height = imagesy($img);

        $blockWidth = (int)($width / $blocks);
        $blockHeight = (int)($height / $blocks);

        $blockHashes = [];

        for ($by = 0; $by < $blocks; $by++)
        {
            for ($bx = 0; $bx < $blocks; $bx++)
            {
                // Create block canvas
                $block = imagecreatetruecolor($size, $size);
                imagecopyresampled(
                    $block,
                    $img,
                    0, 0,
                    $bx * $blockWidth, $by * $blockHeight,
                    $size, $size,
                    $blockWidth, $blockHeight
                );

                // Convert block to grayscale matrix
                $matrix = [];
                for ($y = 0; $y < $size; $y++)
                {
                    for ($x = 0; $x < $size; $x++)
                    {
                        $rgb = imagecolorat($block, $x, $y);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        // Simple edge detection using gradient approximation
                        $gx = (($x < $size - 1 ? ($r * 0.299 + $g * 0.587 + $b * 0.114) -
                            (($rgb = imagecolorat($block, $x + 1, $y)) ?
                            (($rgb >> 16 & 0xFF)*0.299 + (($rgb >> 8)&0xFF)*0.587 + ($rgb&0xFF)*0.114) : 0) : 0));

                        $gy = (($y < $size - 1 ? ($r * 0.299 + $g * 0.587 + $b * 0.114) -
                            (($rgb = imagecolorat($block, $x, $y + 1)) ?
                            (($rgb >> 16 & 0xFF)*0.299 + (($rgb >> 8)&0xFF)*0.587 + ($rgb&0xFF)*0.114) : 0) : 0));

                        $matrix[$y][$x] = sqrt($gx * $gx + $gy * $gy);
                    }
                }

                imagedestroy($block);

                // Apply 2D DCT to block
                $dct = self::dct2($matrix);

                // Keep top-left low-frequency coefficients
                $dctLow = [];
                for ($y = 0; $y < $smallSize; $y++)
                {
                    for ($x = 0; $x < $smallSize; $x++)
                    {
                        $dctLow[] = $dct[$y][$x];
                    }
                }

                // Compute median and generate bits
                $median = self::median($dctLow);
                $bits = '';
                foreach ($dctLow as $v)
                {
                    $bits .= ($v > $median) ? '1' : '0';
                }

                // Repeat/trim to 256 bits
                while (strlen($bits) < 256)
                {
                    $bits .= $bits;
                }

                $bits = substr($bits, 0, 256);

                $blockHashes[] = self::bitsToHex($bits);
            }
        }

        imagedestroy($img);

        return $blockHashes;
    }
}
