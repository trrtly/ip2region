# hyperf ip2region

针对 hyperf 的 [ip2region](https://github.com/lionsoul2014/ip2region) 组件，采用速度最快的 [memorySearch](https://github.com/lionsoul2014/ip2region#ip2region-%E5%B9%B6%E5%8F%91%E4%BD%BF%E7%94%A8) 
方式查询，在框架初始化时就将db文件载入内存

## 安装

```bash
composer require trrtly/ip2region
```

## 示例

```php

use Trrtly\Ip2region\Ip2region;

class Example
{
    /**
     * @Inject
     */
    protected Ip2region $ip2region;

    public function query()
    {
        $address = $this->ip2region->memorySearch($ip);
        var_dump($address);
    }
}
```
