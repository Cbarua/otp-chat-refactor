<?php

namespace App\Utils;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

class OrderedJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalize($record);

        // We want 'datetime' and 'visitor_id' (from extra) at the top level, in order.
        // Normalized record usually has keys like: message, context, level, level_name, channel, datetime, extra

        $ordered = [];

        // 1. Visitor ID (extract from extra if present)
        if (isset($normalized['extra']['visitor_id'])) {
            $ordered['visitor_id'] = $normalized['extra']['visitor_id'];
            // Remove it from extra so it's not duplicated (optional, but cleaner)
            unset($normalized['extra']['visitor_id']);
        }

        // 2. Datetime
        if (isset($normalized['datetime'])) {
            $ordered['datetime'] = $normalized['datetime'];
        }

        // 3. Message
        if (isset($normalized['message'])) {
            $ordered['message'] = $normalized['message'];
        }

        // 4. Add remaining keys
        foreach ($normalized as $key => $value) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $this->toJson($ordered, true) . ($this->appendNewline ? "\n" : '');
    }
}
