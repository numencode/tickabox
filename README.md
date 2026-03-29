# Tickabox Mobile App

---

## Reset application
> adb uninstall com.numencode.tickabox  
> php artisan native:install android --force  
> php artisan native:run android --watch

> php artisan native:jump android

## ADB SHELL

Tinker in emulator shell:
> adb shell

...then go to the app:
> run-as com.numencode.tickabox

...and for example check logs:
> cat /data/user/0/com.numencode.tickabox/app_storage/persisted_data/storage/logs/laravel.log

Retrieve the database from the emulator:
> adb exec-out run-as com.numencode.tickabox cat /data/user/0/com.numencode.tickabox/app_storage/persisted_data/database/database.sqlite > database.sqlite

# Compile front-end assets
> npm run build

---

## Build release problems

When building release with:
> php artisan native:package android --build-type=release

...it might happen that the "Installing Composer dependencies" part gets timeout after 300 sec.

How to fix this:
1. Open file `vendor/nativephp/mobile/src/Traits/PreparesBuild.php`
2. Find line 245 `$this->components->task('Installing Composer dependencies', function () use ($tempDir, $composerArgs) {`
3. Change value in line `->timeout(300)` to `->timeout(3000)`

---

## Author

The **NumenCode Tickabox Mobile App** is created by [Blaz Orazem](https://orazem.si/).

For inquiries, contact: info@numencode.com

---

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


jane@numencode.com
