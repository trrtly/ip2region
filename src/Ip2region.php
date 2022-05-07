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
    public const INDEX_BLOCK_LENGTH = 12;

    public const TOTAL_HEADER_LENGTH = 8192;

    /**
     * db file handler.
     */
    private $dbFileHandler;

    /**
     * header block info.
     */
    private $HeaderSip;

    private $HeaderPtr;

    private $headerLen = 0;

    private $totalBlocks = 0;

    /**
     * for memory mode only
     *  the original db binary string.
     */
    private $dbBinStr;

    private $dbFile;

    private $firstIndexPtr;

    private $lastIndexPtr;

    /**
     * construct method.
     *
     * @param null|mixed $ip2regionFile
     * @throws Exception
     */
    public function __construct($ip2regionFile = null)
    {
        $this->dbFile = is_null($ip2regionFile) ? __DIR__ . '/db/ip2region.db' : $ip2regionFile;
        //check and load the binary string for the first time
        $this->dbBinStr = file_get_contents($this->dbFile);
        if (!$this->dbBinStr) {
            throw new Exception("Fail to open the db file {$this->dbFile}");
        }
        $this->firstIndexPtr = self::getLong($this->dbBinStr, 0);
        $this->lastIndexPtr = self::getLong($this->dbBinStr, 4);
        $this->totalBlocks = ($this->lastIndexPtr - $this->firstIndexPtr) / self::INDEX_BLOCK_LENGTH + 1;
    }

    /**
     * destruct method, resource destroy.
     */
    public function __destruct()
    {
        if ($this->dbFileHandler != null) {
            fclose($this->dbFileHandler);
        }
        $this->dbBinStr = null;
        $this->HeaderSip = null;
        $this->HeaderPtr = null;
    }

    /**
     * all the db binary string will be loaded into memory
     * then search the memory only and this will a lot faster than disk base search.
     * Note:
     * invoke it once before put it to public invoke could make it thread safe.
     *
     * @param string $ip
     * @throws Exception
     * @return null|array
     */
    public function memorySearch($ip)
    {
        if (is_string($ip)) {
            $ip = self::safeIp2long($ip);
        }
        //binary search to define the data
        $l = 0;
        $h = $this->totalBlocks;
        $dataPtr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = $this->firstIndexPtr + $m * self::INDEX_BLOCK_LENGTH;
            $sip = self::getLong($this->dbBinStr, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($this->dbBinStr, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($this->dbBinStr, $p + 8);
                    break;
                }
            }
        }
        //not matched just stop it here
        if ($dataPtr == 0) {
            return null;
        }
        //get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);
        return [
            'city_id' => self::getLong($this->dbBinStr, $dataPtr),
            'region' => substr($this->dbBinStr, $dataPtr + 4, $dataLen - 4),
        ];
    }

    /**
     * get the data block through the specified ip address or long ip numeric with binary search algorithm.
     *
     * @param mixed $ip
     * @return array|null Array or NULL for any error
     * @throws Exception
     */
    public function binarySearch($ip)
    {
        //check and conver the ip address
        if (is_string($ip)) {
            $ip = self::safeIp2long($ip);
        }
        $firstIndexPtr = 0;
        if ($this->totalBlocks == 0) {
            //check and open the original db file
            if ($this->dbFileHandler == null) {
                $this->dbFileHandler = fopen($this->dbFile, 'r');
                if (!$this->dbFileHandler) {
                    throw new Exception("Fail to open the db file {$this->dbFile}");
                }
            }
            fseek($this->dbFileHandler, 0);
            $superBlock = fread($this->dbFileHandler, 8);
            $firstIndexPtr = self::getLong($superBlock, 0);
            $lastIndexPtr = self::getLong($superBlock, 4);
            $this->totalBlocks = ($lastIndexPtr - $firstIndexPtr) / self::INDEX_BLOCK_LENGTH + 1;
        }
        //binary search to define the data
        $l = 0;
        $h = $this->totalBlocks;
        $dataPtr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = $m * self::INDEX_BLOCK_LENGTH;
            fseek($this->dbFileHandler, $firstIndexPtr + $p);
            $buffer = fread($this->dbFileHandler, self::INDEX_BLOCK_LENGTH);
            $sip = self::getLong($buffer, 0);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($buffer, 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($buffer, 8);
                    break;
                }
            }
        }
        //not matched just stop it here
        if ($dataPtr == 0) {
            return null;
        }
        //get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);
        fseek($this->dbFileHandler, $dataPtr);
        $data = fread($this->dbFileHandler, $dataLen);
        return [
            'city_id' => self::getLong($data, 0),
            'region' => substr($data, 4),
        ];
    }

    /**
     * get the data block associated with the specified ip with b-tree search algorithm.
     * Note: not thread safe.
     *
     * @param string ip
     * @param mixed $ip
     * @throws Exception
     * @return mixed Array for NULL for any error
     */
    public function btreeSearch($ip)
    {
        if (is_string($ip)) {
            $ip = self::safeIp2long($ip);
        }
        //check and load the header
        if ($this->HeaderSip == null) {
            //check and open the original db file
            if ($this->dbFileHandler == null) {
                $this->dbFileHandler = fopen($this->dbFile, 'r');
                if ($this->dbFileHandler == false) {
                    throw new Exception("Fail to open the db file {$this->dbFile}");
                }
            }
            fseek($this->dbFileHandler, 8);
            $buffer = fread($this->dbFileHandler, self::TOTAL_HEADER_LENGTH);

            //fill the header
            $idx = 0;
            $this->HeaderSip = [];
            $this->HeaderPtr = [];
            for ($i = 0; $i < self::TOTAL_HEADER_LENGTH; $i += 8) {
                $startIp = self::getLong($buffer, $i);
                $dataPtr = self::getLong($buffer, $i + 4);
                if ($dataPtr == 0) {
                    break;
                }
                $this->HeaderSip[] = $startIp;
                $this->HeaderPtr[] = $dataPtr;
                ++$idx;
            }
            $this->headerLen = $idx;
        }

        //1. define the index block with the binary search
        $l = 0;
        $h = $this->headerLen;
        $sptr = 0;
        $eptr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);

            //perfetc matched, just return it
            if ($ip == $this->HeaderSip[$m]) {
                if ($m > 0) {
                    $sptr = $this->HeaderPtr[$m - 1];
                    $eptr = $this->HeaderPtr[$m];
                } else {
                    $sptr = $this->HeaderPtr[$m];
                    $eptr = $this->HeaderPtr[$m + 1];
                }

                break;
            }

            //less then the middle value
            if ($ip < $this->HeaderSip[$m]) {
                if ($m == 0) {
                    $sptr = $this->HeaderPtr[$m];
                    $eptr = $this->HeaderPtr[$m + 1];
                    break;
                }
                if ($ip > $this->HeaderSip[$m - 1]) {
                    $sptr = $this->HeaderPtr[$m - 1];
                    $eptr = $this->HeaderPtr[$m];
                    break;
                }
                $h = $m - 1;
            } else {
                if ($m == $this->headerLen - 1) {
                    $sptr = $this->HeaderPtr[$m - 1];
                    $eptr = $this->HeaderPtr[$m];
                    break;
                }
                if ($ip <= $this->HeaderSip[$m + 1]) {
                    $sptr = $this->HeaderPtr[$m];
                    $eptr = $this->HeaderPtr[$m + 1];
                    break;
                }
                $l = $m + 1;
            }
        }

        //match nothing just stop it
        if ($sptr == 0) {
            return null;
        }

        //2. search the index blocks to define the data
        $blockLen = $eptr - $sptr;
        fseek($this->dbFileHandler, $sptr);
        $index = fread($this->dbFileHandler, $blockLen + self::INDEX_BLOCK_LENGTH);

        $dataPtr = 0;
        $l = 0;
        $h = $blockLen / self::INDEX_BLOCK_LENGTH;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = (int) ($m * self::INDEX_BLOCK_LENGTH);
            $sip = self::getLong($index, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($index, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($index, $p + 8);
                    break;
                }
            }
        }

        //not matched
        if ($dataPtr == 0) {
            return null;
        }

        //3. get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);

        fseek($this->dbFileHandler, $dataPtr);
        $data = fread($this->dbFileHandler, $dataLen);
        return [
            'city_id' => self::getLong($data, 0),
            'region' => substr($data, 4),
        ];
    }

    /**
     * safe self::safeIp2long function.
     *
     * @param mixed $ip
     *
     * @return false|int|string
     */
    public static function safeIp2long($ip)
    {
        $ip = ip2long($ip);
        // convert signed int to unsigned int if on 32 bit operating system
        if ($ip < 0 && PHP_INT_SIZE == 4) {
            $ip = sprintf('%u', $ip);
        }
        return $ip;
    }

    /**
     * read a long from a byte buffer.
     *
     * @param mixed $b
     * @param mixed $offset
     * @return int|string
     */
    public static function getLong($b, $offset)
    {
        $val = (
            (ord($b[$offset++])) |
            (ord($b[$offset++]) << 8) |
            (ord($b[$offset++]) << 16) |
            (ord($b[$offset]) << 24)
        );
        // convert signed int to unsigned int if on 32 bit operating system
        if ($val < 0 && PHP_INT_SIZE == 4) {
            $val = sprintf('%u', $val);
        }
        return $val;
    }
}
