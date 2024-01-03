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
namespace HyperfTest\Cases;

use Trrtly\Ip2region\Ip2region;

use function Hyperf\Support\make;

class Ip2regionTest extends AbstractTestCase
{
    public function testIp2regionMemorySearch()
    {
        $ip2region = make(Ip2region::class);
        $region = $ip2region->memorySearch('120.41.166.1');
        $this->assertIsArray($region);
        $this->assertArrayHasKey('region', $region);
        $this->assertStringContainsString('厦门', $region['region']);
    }

    public function testIp2regionGetHeader()
    {
        /** @var Ip2region $ip2region */
        $ip2region = make(Ip2region::class);
        $header = $ip2region->getHeader();
        $this->assertIsArray($header);
        $this->assertArrayHasKey('version', $header);
    }
}
