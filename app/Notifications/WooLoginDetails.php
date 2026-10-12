<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * "Forgot password" for an ERP customer: their password is their WooCommerce (website) password,
 * which only the ERP can change, so instead of a reset link they get their current login details,
 * the same way the ERP's own "Reset password" email sends them.
 */
class WooLoginDetails extends Notification
{
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $from = config('mail.from.name') ?: config('app.name');

        return (new MailMessage)
            ->subject("Your {$from} login details")
            ->greeting("Hello {$notifiable->name},")
            ->line('You asked for your login details. Use them to sign in to the app and the website:')
            ->line(new HtmlString('**Username:** '.self::literal($notifiable->wooLoginName())))
            ->line(new HtmlString('**Password:** '.self::literal($notifiable->woo_password)))
            ->line('You can also sign in with your email address instead of the username. To change your password, please contact us.')
            ->line('If you did not ask for this, you can ignore this email.')
            ->salutation("Thank you,\n\n{$from}");
    }

    /**
     * A Markdown code span, so the email shows the value exactly: no emphasis from * or _, no HTML
     * entities. The fence is longer than any run of backticks in the value, and the padding spaces
     * are the ones Markdown strips.
     */
    private static function literal(string $value): string
    {
        preg_match_all('/`+/', $value, $runs);
        $fence = str_repeat('`', max([0, ...array_map('strlen', $runs[0])]) + 1);

        return "{$fence} {$value} {$fence}";
    }
}
