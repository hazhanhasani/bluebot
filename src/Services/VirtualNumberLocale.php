<?php

declare(strict_types=1);

/**
 * Shared presentation dictionary for virtual-number catalogs.
 *
 * Provider APIs are free to use their own IDs/slugs. BlueBot keeps those
 * provider identifiers untouched and only normalizes customer-facing labels.
 */
final class BluebotVirtualNumberLocale
{
    private const SERVICES = [
        'telegram' => 'تلگرام',
        'instagram' => 'اینستاگرام',
        'whatsapp' => 'واتساپ',
        'viber' => 'وایبر',
        'wechat' => 'وی‌چت',
        'google' => 'گوگل / جیمیل / یوتیوب',
        'facebook' => 'فیسبوک',
        'twitter' => 'X / توییتر',
        'microsoft' => 'مایکروسافت',
        'line' => 'لاین',
        'yahoo' => 'یاهو',
        'linkedin' => 'لینکدین',
        'paypal' => 'پی‌پال',
        'tinder' => 'تیندر',
        'discord' => 'دیسکورد',
        'apple' => 'اپل',
        'amazon' => 'آمازون',
        'tiktok' => 'تیک‌تاک',
        'uber' => 'اوبر',
        'steam' => 'استیم',
        'signal' => 'سیگنال',
        'openai' => 'OpenAI / ChatGPT',
        'chatgpt' => 'ChatGPT',
        'snapchat' => 'اسنپ‌چت',
        'spotify' => 'اسپاتیفای',
        'netflix' => 'نتفلیکس',
        'airbnb' => 'Airbnb',
        'bolt' => 'Bolt',
        'binance' => 'بایننس',
        'coinbase' => 'کوین‌بیس',
    ];

    private const COUNTRIES = [
        'ukraine' => 'اوکراین',
        'kazakhstan' => 'قزاقستان',
        'philippines' => 'فیلیپین',
        'indonesia' => 'اندونزی',
        'malaysia' => 'مالزی',
        'kenya' => 'کنیا',
        'tanzania' => 'تانزانیا',
        'vietnam' => 'ویتنام',
        'latvia' => 'لتونی',
        'romania' => 'رومانی',
        'estonia' => 'استونی',
        'russia' => 'روسیه',
        'united states' => 'آمریکا',
        'usa' => 'آمریکا',
        'united kingdom' => 'بریتانیا',
        'uk' => 'بریتانیا',
        'germany' => 'آلمان',
        'france' => 'فرانسه',
        'netherlands' => 'هلند',
        'canada' => 'کانادا',
        'australia' => 'استرالیا',
        'india' => 'هند',
        'pakistan' => 'پاکستان',
        'turkey' => 'ترکیه',
        'iran' => 'ایران',
        'iraq' => 'عراق',
        'united arab emirates' => 'امارات',
        'uae' => 'امارات',
        'saudi arabia' => 'عربستان سعودی',
        'egypt' => 'مصر',
        'brazil' => 'برزیل',
        'mexico' => 'مکزیک',
        'argentina' => 'آرژانتین',
        'spain' => 'اسپانیا',
        'italy' => 'ایتالیا',
        'poland' => 'لهستان',
        'sweden' => 'سوئد',
        'norway' => 'نروژ',
        'finland' => 'فنلاند',
        'denmark' => 'دانمارک',
        'japan' => 'ژاپن',
        'south korea' => 'کره جنوبی',
        'korea' => 'کره جنوبی',
        'china' => 'چین',
        'hong kong' => 'هنگ‌کنگ',
        'thailand' => 'تایلند',
        'singapore' => 'سنگاپور',
        'south africa' => 'آفریقای جنوبی',
        'nigeria' => 'نیجریه',
    ];

    public static function serviceLabel(string $code, string $fallback = ''): string
    {
        $key = strtolower(trim($code));
        if ($key !== '' && isset(self::SERVICES[$key])) {
            return self::SERVICES[$key];
        }

        $fallback = trim($fallback);
        return $fallback !== '' ? $fallback : ($key !== '' ? $key : 'سرویس شماره مجازی');
    }

    public static function countryLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'کشور نامشخص';
        }

        $key = strtolower($value);
        return self::COUNTRIES[$key] ?? $value;
    }
}
