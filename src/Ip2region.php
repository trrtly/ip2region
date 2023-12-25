<?php

declare(strict_types=1);
/**
 * This file is part of the hyperf-ip2region.
 *
 * (c) trrtly <328602875@qq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Trrtly\Ip2region;

use Exception;

class Ip2region
{
    public const HeaderInfoLength = 256;

    public const VectorIndexRows = 256;

    public const VectorIndexCols = 256;

    public const VectorIndexSize = 8;

    public const SegmentIndexSize = 14;

    // vector index in binary string.
    // string decode will be faster than the map based Array.
    private $vectorIndex;

    // xdb content buffer
    private $contentBuff;

    /**
     * initialize the xdb searcher.
     *
     * @param null|mixed $dbFile
     * @throws Exception
     */
    public function __construct($dbFile = null)
    {
        if (is_null($dbFile)) {
            $dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'ip2region.xdb';
        }

        $cBuff = file_get_contents($dbFile);
        if (! $cBuff) {
            throw new Exception("Fail to open the db file {$dbFile}");
        }

        $this->vectorIndex = null;
        $this->contentBuff = $cBuff;
    }

    /**
     * 获取文件头信息.
     */
    public function getHeader(): ?array
    {
        $buff = $this->read(0, self::HeaderInfoLength);
        if ($buff === false) {
            return null;
        }

        // read bytes length checking
        if (strlen($buff) != self::HeaderInfoLength) {
            return null;
        }

        // return the decoded header info
        return [
            'version' => self::getShort($buff, 0),
            'indexPolicy' => self::getShort($buff, 2),
            'createdAt' => self::getLong($buff, 4),
            'startIndexPtr' => self::getLong($buff, 8),
            'endIndexPtr' => self::getLong($buff, 12),
        ];
    }

    /**
     * find the region info for the specified ip address.
     *
     * @param string|int $ip
     * @throws Exception
     */
    public function search($ip): ?string
    {
        // check and convert the sting ip to a 4-bytes long
        if (is_string($ip)) {
            $t = self::ip2long($ip);
            if ($t === null) {
                throw new Exception("invalid ip address `{$ip}`");
            }
            $ip = $t;
        }

        // locate the segment index block based on the vector index
        $il0 = ($ip >> 24) & 0xFF;
        $il1 = ($ip >> 16) & 0xFF;
        $idx = $il0 * self::VectorIndexCols * self::VectorIndexSize + $il1 * self::VectorIndexSize;
        if ($this->vectorIndex != null) {
            $sPtr = self::getLong($this->vectorIndex, $idx);
            $ePtr = self::getLong($this->vectorIndex, $idx + 4);
        } elseif ($this->contentBuff != null) {
            $sPtr = self::getLong($this->contentBuff, self::HeaderInfoLength + $idx);
            $ePtr = self::getLong($this->contentBuff, self::HeaderInfoLength + $idx + 4);
        } else {
            // read the vector index block
            $buff = $this->read(self::HeaderInfoLength + $idx, 8);
            if ($buff === null) {
                throw new Exception("failed to read vector index at {$idx}");
            }

            $sPtr = self::getLong($buff, 0);
            $ePtr = self::getLong($buff, 4);
        }

        // printf("sPtr: %d, ePtr: %d\n", $sPtr, $ePtr);

        // binary search the segment index to get the region info
        $dataLen = 0;
        $dataPtr = null;
        $l = 0;
        $h = ($ePtr - $sPtr) / self::SegmentIndexSize;
        while ($l <= $h) {
            $m = ($l + $h) >> 1;
            $p = $sPtr + $m * self::SegmentIndexSize;

            // read the segment index
            $buff = $this->read($p, self::SegmentIndexSize);
            if ($buff == null) {
                throw new Exception("failed to read segment index at {$p}");
            }

            $sip = self::getLong($buff, 0);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($buff, 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataLen = self::getShort($buff, 8);
                    $dataPtr = self::getLong($buff, 10);
                    break;
                }
            }
        }

        // match nothing interception.
        // @TODO: could this even be a case ?
        // printf("dataLen: %d, dataPtr: %d\n", $dataLen, $dataPtr);
        if ($dataPtr == null) {
            return null;
        }

        // load and return the region data
        $buff = $this->read($dataPtr, $dataLen);
        if ($buff == null) {
            return null;
        }

        return $buff;
    }

    /**
     * all the db binary string will be loaded into memory
     * then search the memory only and this will a lot faster than disk base search.
     * Note:
     * invoke it once before put it to public invoke could make it thread safe.
     *
     * @param string|int $ip
     * @throws Exception
     */
    public function memorySearch($ip): ?array
    {
        $region = $this->search($ip);

        if (is_null($region)) {
            return null;
        }

        return ['city_id' => 0, 'region' => $region];
    }

    /**
     * get the data block through the specified ip address or long ip numeric with binary search algorithm.
     *
     * @param string|int $ip
     * @throws Exception
     */
    public function binarySearch($ip): ?array
    {
        return $this->memorySearch($ip);
    }

    /**
     * get the data block associated with the specified ip with b-tree search algorithm.
     * Note: not thread safe.
     *
     * @param string|int $ip
     * @throws Exception
     */
    public function btreeSearch($ip): ?array
    {
        return $this->memorySearch($ip);
    }

    // --- static util functions ----

    // convert a string ip to long
    public static function ip2long($ip)
    {
        $ip = ip2long($ip);
        if ($ip === false) {
            return null;
        }

        // convert signed int to unsigned int if on 32 bit operating system
        if ($ip < 0 && PHP_INT_SIZE == 4) {
            $ip = sprintf('%u', $ip);
        }

        return $ip;
    }

    // read a 4bytes long from a byte buffer
    public static function getLong($b, $idx)
    {
        $val = ord($b[$idx]) | (ord($b[$idx + 1]) << 8)
            | (ord($b[$idx + 2]) << 16) | (ord($b[$idx + 3]) << 24);

        // convert signed int to unsigned int if on 32 bit operating system
        if ($val < 0 && PHP_INT_SIZE == 4) {
            $val = sprintf('%u', $val);
        }

        return $val;
    }

    // read a 2bytes short from a byte buffer
    public static function getShort($b, $idx)
    {
        return ord($b[$idx]) | (ord($b[$idx + 1]) << 8);
    }

    // read specified bytes from the specified index
    private function read($offset, $len)
    {
        return substr($this->contentBuff, $offset, $len);
    }
}
