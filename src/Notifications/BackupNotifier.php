<?php

namespace MahmoudMhamed\BackupStation\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BackupNotifier
{
    /**
     * @param string $event 'success' | 'failure'
     */
    public function send(string $event, array $context): void
    {
        $key = "on_{$event}";
        $cfg = config("backup-station.notifications.{$key}");

        if (!$cfg || empty($cfg['enabled'])) {
            return;
        }

        $channels = (array) ($cfg['channels'] ?? []);

        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'log' => $this->sendLog($event, $context),
                    'mail' => $this->sendMail($event, $context),
                    'slack' => $this->sendSlack($event, $context),
                    'telegram' => $this->sendTelegram($event, $context),
                    'discord' => $this->sendDiscord($event, $context),
                    default => null,
                };
            } catch (Throwable $e) {
                Log::warning('[BackupStation] Notification channel ['.$channel.'] failed: '.$e->getMessage());
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Channels                                                            */
    /* ------------------------------------------------------------------ */

    protected function sendLog(string $event, array $context): void
    {
        $channel = config('backup-station.notifications.channels.log.channel', 'stack');
        $line = '[BackupStation] ' . $this->plainText($event, $context);

        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $event === 'success' ? $logger->info($line) : $logger->error($line);
    }

    protected function sendMail(string $event, array $context): void
    {
        $cfg = config('backup-station.notifications.channels.mail', []);
        $to = $this->normalizeRecipients($cfg['to'] ?? []);

        if (!$to) {
            return;
        }

        $subject = ($event === 'success' ? '✅ Backup successful' : '❌ Backup FAILED')
            . ' — ' . config('app.name');

        $body = $this->htmlBody($event, $context);

        $mailer = $cfg['mailer'] ?: null;
        $sender = Mail::mailer($mailer);

        $send = function () use ($sender, $to, $cfg, $subject, $body) {
            $sender->html($body, function ($message) use ($to, $cfg, $subject) {
                $message->to($to)->subject($subject);
                if (!empty($cfg['from'])) {
                    $message->from($cfg['from']);
                }
            });
        };

        $this->runMaybeQueued($send, !empty($cfg['queue']));
    }

    /**
     * Normalize a recipient list. Accepts:
     *   - array:  ['a@x.com', 'b@x.com']
     *   - string: 'a@x.com, b@x.com'
     *   - csv via env: parsed at config time, returned as array
     */
    protected function normalizeRecipients(mixed $value): array
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value)) {
            $list = explode(',', $value);
        } else {
            return [];
        }

        $list = array_map(fn ($v) => trim((string) $v), $list);
        return array_values(array_filter($list, fn ($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * Either run the closure immediately, or dispatch it onto the queue
     * (after the HTTP response is sent) when the channel has queue=true.
     */
    protected function runMaybeQueued(\Closure $send, bool $queue): void
    {
        if ($queue && function_exists('dispatch')) {
            try {
                dispatch($send)->afterResponse();
                return;
            } catch (Throwable) {
                // fall through to sync if dispatch fails (no queue worker etc.)
            }
        }
        $send();
    }

    protected function sendSlack(string $event, array $context): void
    {
        $cfg = config('backup-station.notifications.channels.slack', []);
        $webhook = $cfg['webhook'] ?? null;
        if (!$webhook) {
            return;
        }

        $color = $event === 'success' ? '#16a34a' : '#dc2626';
        $title = $event === 'success' ? '✅ Backup successful' : '❌ Backup FAILED';

        $fields = [];
        foreach (['filename','database','driver','connection','size','duration','tables','mode','note','user','time'] as $k) {
            if (!empty($context[$k])) {
                $fields[] = [
                    'title' => ucfirst(str_replace('_', ' ', $k)),
                    'value' => (string) $context[$k],
                    'short' => true,
                ];
            }
        }
        if (!empty($context['error'])) {
            $fields[] = ['title' => 'Error', 'value' => (string) $context['error'], 'short' => false];
        }

        $payload = [
            'username' => $cfg['username'] ?? 'Backup Station',
            'icon_emoji' => $cfg['emoji'] ?? ':floppy_disk:',
            'attachments' => [[
                'color' => $color,
                'title' => $title,
                'text' => '*' . config('app.name') . '* — ' . ($context['time'] ?? now()->toDateTimeString()),
                'fields' => $fields,
                'footer' => 'backup-station',
                'ts' => time(),
                'mrkdwn_in' => ['text'],
            ]],
        ];

        $this->runMaybeQueued(function () use ($webhook, $payload) {
            Http::asJson()->post($webhook, $payload)->throw();
        }, !empty($cfg['queue']));
    }

    protected function sendTelegram(string $event, array $context): void
    {
        $cfg = config('backup-station.notifications.channels.telegram', []);
        $token = $cfg['bot_token'] ?? null;
        $chatId = $cfg['chat_id'] ?? null;
        if (!$token || !$chatId) {
            return;
        }

        $emoji = $event === 'success' ? '✅' : '❌';
        $text = $emoji . ' *' . config('app.name') . "*\n\n" . $this->plainText($event, $context);

        $this->runMaybeQueued(function () use ($token, $chatId, $text) {
            Http::asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ])->throw();
        }, !empty($cfg['queue']));
    }

    protected function sendDiscord(string $event, array $context): void
    {
        $cfg = config('backup-station.notifications.channels.discord', []);
        $webhook = $cfg['webhook'] ?? null;
        if (!$webhook) {
            return;
        }

        $color = $event === 'success' ? 0x16a34a : 0xdc2626;
        $title = $event === 'success' ? '✅ Backup successful' : '❌ Backup FAILED';

        $payload = [
            'embeds' => [[
                'title' => $title,
                'description' => $this->plainText($event, $context),
                'color' => $color,
                'footer' => ['text' => config('app.name')],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];

        $this->runMaybeQueued(function () use ($webhook, $payload) {
            Http::asJson()->post($webhook, $payload)->throw();
        }, !empty($cfg['queue']));
    }

    /* ------------------------------------------------------------------ */
    /* Body builders                                                       */
    /* ------------------------------------------------------------------ */

    protected function plainText(string $event, array $context): string
    {
        $lines = [];
        $lines[] = $event === 'success'
            ? "Backup created [{$context['filename']}]"
            : 'Backup failed for connection [' . ($context['connection'] ?? '-') . ']';

        if (!empty($context['database'])) $lines[] = 'Database: ' . $context['database'];
        if (!empty($context['driver'])) $lines[] = 'Driver: ' . $context['driver'];
        if (!empty($context['connection'])) $lines[] = 'Connection: ' . $context['connection'];
        if (!empty($context['size'])) $lines[] = 'Size: ' . $context['size'];
        if (!empty($context['duration'])) $lines[] = 'Duration: ' . $context['duration'];
        if (!empty($context['tables'])) $lines[] = 'Tables: ' . $context['tables'];
        if (!empty($context['mode'])) $lines[] = 'Mode: ' . $context['mode'];
        if (!empty($context['user'])) $lines[] = 'User: ' . $context['user'];
        if (!empty($context['note'])) $lines[] = 'Note: ' . $context['note'];
        if (!empty($context['error'])) $lines[] = 'Error: ' . $context['error'];
        $lines[] = 'Time: ' . ($context['time'] ?? now()->toDateTimeString());

        return implode("\n", $lines);
    }

    protected function htmlBody(string $event, array $context): string
    {
        $rows = '';
        foreach (['filename','connection','database','driver','size','duration','tables','mode','user','note','error','time'] as $key) {
            if (empty($context[$key])) continue;
            $label = ucfirst($key);
            $val = e($context[$key]);
            $rows .= "<tr><td style='padding:6px 12px;color:#6b7280;'>{$label}</td><td style='padding:6px 12px;font-family:monospace'>{$val}</td></tr>";
        }

        $color = $event === 'success' ? '#16a34a' : '#dc2626';
        $title = $event === 'success' ? '✅ Backup successful' : '❌ Backup FAILED';

        $app = e((string) config('app.name'));

        return <<<HTML
<div style="font-family:Inter,Arial,sans-serif;max-width:600px;margin:auto">
    <h2 style="color:{$color}">{$title}</h2>
    <p style="color:#374151">App: <strong>{$app}</strong></p>
    <table style="border-collapse:collapse;width:100%;background:#f9fafb;border-radius:8px;overflow:hidden">{$rows}</table>
</div>
HTML;
    }
}
