<?php

namespace App\Enums;

enum EmailType: string
{
    case OTP_VERIFICATION = 'OTP_VERIFICATION';
    case PASSWORD_RESET = 'PASSWORD_RESET';
    case SECURITY_ALERT = 'SECURITY_ALERT';
}
