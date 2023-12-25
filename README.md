# hyperf ip2region

针对 hyperf 的 [ip2region](https://github.com/lionsoul2014/ip2region) 组件，采用速度最快的 [memorySearch](https://github.com/lionsoul2014/ip2region/blob/master/binding/php/ReadMe.md#%E7%BC%93%E5%AD%98%E6%95%B4%E4%B8%AA-xdb-%E6%95%B0%E6%8D%AE) 
方式查询，在框架初始化时就将db文件载入内存

参考
- [ip2region](https://github.com/lionsoul2014/ip2region?tab=readme-ov-file#2%E6%8A%80%E6%9C%AF%E8%B5%84%E6%BA%90%E5%88%86%E4%BA%AB)
- [数据结构和查询过程](https://mp.weixin.qq.com/s/ndjzu0BgaeBmDOCw5aqHUg)
- [兼容Ip2Region](https://github.com/zoujingli/ip2region/blob/master/Ip2Region.php)

## 安装

```bash
composer require trrtly/ip2region
```

## 示例

```php

use Trrtly\Ip2region\Ip2region;
use Hyperf\Di\Annotation\Inject;

class Example
{
    /**
     * @Inject
     * @var Ip2region
     */
    protected $ip2region;

    public function query()
    {
        $address = $this->ip2region->memorySearch($ip);
        var_dump($address);
    }
}
```
