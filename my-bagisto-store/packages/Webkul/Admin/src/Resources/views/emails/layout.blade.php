<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />

    </head>

    <body style="font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', 'Segoe UI', sans-serif;">
        <div style="max-width: 640px; margin-left: auto; margin-right: auto;">
            <div style="padding: 30px;">
                <!-- Email Header -->
                <div style="margin-bottom: 65px;">
                    <a href="{{ route('shop.home.index') }}">
                        @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
                            <img
                                src="{{ Storage::url($logo) }}"
                                alt="{{ config('app.name') }}"
                                style="height: 40px; width: 110px;"
                            />
                        @else
                            <img
                                src="{{ bagisto_asset('images/logo.svg', 'admin') }}"
                                alt="{{ config('app.name') }}"
                                style="width: 156px;height: 40px;"
                            />
                        @endif
                    </a>
                </div>

                <!-- Email Content -->
                {{ $slot }}

                <!-- Email Footer -->
                <p style="font-size: 16px;color: #202B3C;line-height: 24px;">
                    @lang('admin::app.emails.thanks', [
                        'link' => 'mailto:' . core()->getContactEmailDetails()['email'],
                        'email' => core()->getContactEmailDetails()['email'],
                        'style' => 'color: #2969FF;'
                    ])
                </p>
            </div>
        </div>
    </body>
</html>
