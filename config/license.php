<?php

return [
    /*
    |--------------------------------------------------------------------------
    | License Public Key
    |--------------------------------------------------------------------------
    |
    | المفتاح العام للتحقق من التوقيع الرقمي للترخيص (RSA Public Key).
    | يُستخدم حصراً للتحقق من صحة توقيع كود التفعيل دون كشف المفتاح الخاص.
    |
    */
    'public_key' => env('LICENSE_PUBLIC_KEY', <<<EOT
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA3G+EZObiZbtoR3/n/b3z
3JhkUUz+qGllbuEHOWTJfVqCUTx67KbkUxP63N1w3chfAnZ0V2gHIxGb8LCyng/p
PwjjRVMbqyg87OGLVlP5dLyQ9p29T/skvuZcfxQbnJB+JCCxXiiOvti37RtJlyaG
047v1iGfmhQ8QcldkMj5NWE+4Z1J64Jgf6GNqgcQm0h70JLJwfOS76gwUnQRqJXY
fSWYgvv8hZOsjufyxPjLJMFmiPsI9Y5pyPj4X/itl9yf4MD+ZjZwVfwg1PdEI3vt
u/qHgwqtuHIGi/JUlXRlcyJLskjBI9Ty8YMmh7TtB2Sjfq78rq7+rQi/UMxCsuEf
RQIDAQAB
-----END PUBLIC KEY-----
EOT
    ),

    /*
    |--------------------------------------------------------------------------
    | Master Online Key & Hash Verification
    |--------------------------------------------------------------------------
    |
    | مفتاح الأمان للسيرفر السحابي (أونلاين). يتم التحقق منه بمطابقة تجزئة SHA-256
    | المشفرة مع المفتاح المدخل، لمنع أي تجاوز عبر إدخال نصوص عشوائية في .env
    |
    */
    'online_master_key' => env('ONLINE_MASTER_KEY', null),

    'online_master_hash' => env('ONLINE_MASTER_HASH', '34f750f2dd54b4e844101fd7b7be662c03f8dcb8438ec6402d0380a4f7953751'),

    /*
    |--------------------------------------------------------------------------
    | Free Trial Configuration
    |--------------------------------------------------------------------------
    |
    | مدة الفترة التجريبية المجانية للأجهزة الجديدة غير المرخصة بالأيام.
    |
    */
    'trial_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Warning Period (Days)
    |--------------------------------------------------------------------------
    |
    | عدد الأيام المتبقية على انتهاء الترخيص لبدء إرسال تنبيه للمستخدم.
    |
    */
    'warning_days_before_expiry' => 7,
];