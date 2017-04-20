# oxipay-magento-1.x [![Build status](https://ci.appveyor.com/api/projects/status/t71e6r0lvsfriwm0/branch/master?svg=true)](https://ci.appveyor.com/project/oxipay/oxipay-magento-1-x/branch/master)

## Installation

To deploy the plugin, clone this repo, and then copy the app directory into your magento instance. E.g.

```bash
git clone https://github.com/oxipay/oxipay-magento-1.x ~/oxipay

cp ~/oxipay/app/* /path/to/magento/app

```

Once copied - you should be able to see the oxipay plugin loaded in magento (note this may require a cache flush/site reload)

## Varnish cache exclusions

A rule must be added to varnish configuration for any magento installation running behind a varnish backend. (Or any other proxy cache) to invalidate any payment controller action.

Must exclude: `.*oxipayments.`* from all caching.