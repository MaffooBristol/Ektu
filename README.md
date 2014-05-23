![Ektu logo](resources/ektu-logo-small.png)
===

Full PHP CLI interface for Amazon EC2.

Requirements
---
* [PHP 5.3+](http://uk3.php.net/manual/en/install.php)
* [Composer](https://getcomposer.org/doc/00-intro.md) (Install it globally)
* Mac or Linux. May work on Win but not tested.

Installation
---
```bash
git clone git@github.com:/MaffooBristol/Ektu "ektu" && cd $_
composer install
php ektu.init.php
```

Usage
---
After installation, it should create an alias to the script so that you can run 'ektu [command] [optional instance]' globally, otherwise run 'php /path/to/ektu.init.php [command] [optional instance]'. It should give you available options.

```
   Copyright 2014 Matt Fletcher

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
```
