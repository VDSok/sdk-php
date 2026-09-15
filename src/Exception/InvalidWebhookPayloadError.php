<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * Подпись верна, но тело не похоже на конверт `WebhookEvent` (нет `id`,
 * `type`, `data.object`...). Отдельный класс, чтобы получатель мог
 * различать «подделка» (WebhookSignatureError) и «новый формат, который
 * SDK ещё не понимает».
 */
class InvalidWebhookPayloadError extends VdsokException
{
}
