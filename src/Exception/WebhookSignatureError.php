<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * Подпись вебхука не сошлась, заголовки отсутствуют или метка времени
 * вне допуска. Получатель должен ответить 400 и НЕ обрабатывать событие.
 * Подробности ошибки в сообщении общие — они не должны помогать
 * подбирать подпись.
 */
class WebhookSignatureError extends VdsokException
{
}
