<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\AudioResponse;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;

interface AudioProviderInterface
{
    public function name(): string;
    public function synthesize(AudioRequest $request): AudioResponse;
    public function transcribe(TranscriptionRequest $request): TranscriptionResponse;
}
