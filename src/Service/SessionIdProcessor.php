<?php
// src/Service/SessionIdProcessor.php

namespace App\Service;

use App\Enum\SessionKey;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class SessionIdProcessor implements ProcessorInterface
{
    private SessionService $sessionService;

    /**
     * @param SessionService $sessionService An instance of our session wrapper.
     */
    public function __construct(SessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    /**
     * Adds the visitor_id from the session to the log record.
     *
     * @param LogRecord $record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->sessionService->has(SessionKey::VISITOR_ID)) {
            $record->extra['visitor_id'] = $this->sessionService->get(SessionKey::VISITOR_ID);
        }
        return $record;
    }
}
