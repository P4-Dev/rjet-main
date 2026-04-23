# Notificações - Guideline

> **Regras para envio de notificações por email, SMS, database e broadcast.**

---

## 1. Canais Disponíveis

| Canal | Uso | Dependência |
|-------|-----|-------------|
| `mail` | Emails transacionais | SMTP / Mailgun / SES |
| `database` | Notificações in-app (badge, dropdown) | Tabela `notifications` |
| `broadcast` | Real-time (WebSocket) | Pusher / Soketi / Redis |
| `sms` | SMS (via Vonage/Twilio) | Pacote externo |
| `slack` | Alertas para equipe | Webhook URL |

---

## 2. Estrutura da Notificação

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
    ) {
        $this->onQueue('default');
    }

    /**
     * Canais de entrega.
     *
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.order_created.subject'))
            ->greeting(__('notifications.order_created.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.order_created.line', ['number' => $this->order->number]))
            ->action(__('notifications.order_created.action'), route('orders.show', $this->order))
            ->line(__('notifications.order_created.thanks'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_created',
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
            'message' => __('notifications.order_created.database', [
                'number' => $this->order->number,
            ]),
        ];
    }
}
```

---

## 3. Envio

```php
// Para um usuário
$user->notify(new OrderCreatedNotification($order));

// Para múltiplos usuários
Notification::send($admins, new OrderCreatedNotification($order));

// Via facade (sem model)
Notification::route('mail', 'admin@empresa.com')
    ->notify(new OrderCreatedNotification($order));
```

---

## 4. Notificações Filament (In-App)

### Via Filament Notifications (Toast)

```php
use Filament\Notifications\Notification;

// Sucesso
Notification::make()
    ->title(__('notifications.order_created.toast'))
    ->success()
    ->send();

// Com body e ação
Notification::make()
    ->title(__('notifications.order_created.toast'))
    ->body(__('notifications.order_created.body', ['number' => $order->number]))
    ->success()
    ->actions([
        \Filament\Notifications\Actions\Action::make('view')
            ->label(__('common.actions.view'))
            ->url(route('filament.admin.resources.orders.view', $order)),
    ])
    ->sendToDatabase($user); // Salva no banco + mostra no bell icon
```

### Via Filament Database Notifications

```php
// Enviar para o dropdown de notificações do Filament
Notification::make()
    ->title('Novo pedido #' . $order->number)
    ->icon('heroicon-o-shopping-cart')
    ->iconColor('success')
    ->body('Total: R$ ' . number_format($order->total, 2, ',', '.'))
    ->actions([
        \Filament\Notifications\Actions\Action::make('view')
            ->label(__('common.actions.view'))
            ->markAsRead()
            ->url(route('filament.admin.resources.orders.view', $order)),
    ])
    ->sendToDatabase($user);
```

---

## 5. Mail Templates

### Mailable Class

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrderConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('emails.order_confirmation.subject', ['number' => $this->order->number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.confirmation',
            with: [
                'order' => $this->order,
                'items' => $this->order->items,
            ],
        );
    }
}
```

### Markdown Template

```blade
{{-- resources/views/emails/orders/confirmation.blade.php --}}
<x-mail::message>
# {{ __('emails.order_confirmation.heading') }}

{{ __('emails.order_confirmation.body', ['number' => $order->number]) }}

<x-mail::table>
| {{ __('emails.product') }} | {{ __('emails.quantity') }} | {{ __('emails.price') }} |
|:---|:---:|---:|
@foreach ($items as $item)
| {{ $item->product->name }} | {{ $item->quantity }} | R$ {{ number_format($item->price, 2, ',', '.') }} |
@endforeach
| **{{ __('emails.total') }}** | | **R$ {{ number_format($order->total, 2, ',', '.') }}** |
</x-mail::table>

<x-mail::button :url="route('orders.show', $order)">
{{ __('emails.order_confirmation.action') }}
</x-mail::button>

{{ __('emails.thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
```

---

## 6. Traduções

```php
// lang/pt_BR/notifications.php
return [
    'order_created' => [
        'subject' => 'Pedido #:number criado',
        'greeting' => 'Olá :name!',
        'line' => 'Seu pedido #:number foi criado com sucesso.',
        'action' => 'Ver Pedido',
        'thanks' => 'Obrigado pela preferência!',
        'toast' => 'Pedido criado com sucesso!',
        'body' => 'Pedido #:number registrado.',
        'database' => 'Novo pedido #:number criado.',
    ],
];

// lang/en/notifications.php
return [
    'order_created' => [
        'subject' => 'Order #:number created',
        'greeting' => 'Hello :name!',
        'line' => 'Your order #:number was created successfully.',
        'action' => 'View Order',
        'thanks' => 'Thank you!',
        'toast' => 'Order created successfully!',
        'body' => 'Order #:number registered.',
        'database' => 'New order #:number created.',
    ],
];
```

---

## 7. Preferências de Notificação

Se o projeto precisar que usuários escolham quais notificações receber:

```php
// Migration
$table->json('notification_preferences')->nullable();

// Model
protected function casts(): array
{
    return [
        'notification_preferences' => 'array',
    ];
}

// Na Notification
public function via(object $notifiable): array
{
    $channels = ['database']; // Sempre database

    $prefs = $notifiable->notification_preferences ?? [];

    if ($prefs['email'] ?? true) {
        $channels[] = 'mail';
    }

    return $channels;
}
```

---

## 8. Testes

```php
<?php

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderCreatedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

describe('OrderCreatedNotification', function () {
    it('sends via mail and database', function () {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create();

        $user->notify(new OrderCreatedNotification($order));

        Notification::assertSentTo($user, OrderCreatedNotification::class, function ($notification, $channels) {
            return in_array('mail', $channels) && in_array('database', $channels);
        });
    });

    it('contains correct mail data', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create(['number' => 'ORD-001']);

        $notification = new OrderCreatedNotification($order);
        $mail = $notification->toMail($user);

        expect($mail->subject)->toContain('ORD-001');
    });

    it('contains correct database data', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create();

        $notification = new OrderCreatedNotification($order);
        $data = $notification->toArray($user);

        expect($data)
            ->type->toBe('order_created')
            ->order_id->toBe($order->id);
    });
});
```

---

## 9. Checklist

- [ ] Notification implementa `ShouldQueue` (nunca síncrona)
- [ ] Canais definidos em `via()` com base nas preferências do usuário
- [ ] Textos usando `__()` para traduções
- [ ] Arquivo `lang/pt_BR/notifications.php` criado
- [ ] Arquivo `lang/en/notifications.php` criado
- [ ] Template de email usando Markdown Laravel (`x-mail::message`)
- [ ] Testes com `Notification::fake()`
- [ ] Filament: usar `Notification::make()->sendToDatabase()` para in-app
