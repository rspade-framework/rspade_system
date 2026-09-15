<?php

namespace App\RSpade\CodeQuality;

#[Instantiatable]
class CodeQuality_Violation
{
    public function __construct(
        public readonly string $rule_id,
        public readonly string $file_path,
        public readonly int $line_number,
        public readonly string $message,
        public readonly string $severity,
        public readonly ?string $code_snippet = null,
        public readonly ?string $suggestion = null
    ) {}
    
    public function to_array(): array
    {
        // The wire format rsx:check's output and the test suite's assertions both key off
        return [
            'file' => $this->file_path,
            'line' => $this->line_number,
            'type' => $this->rule_id,
            'message' => $this->message,
            'resolution' => $this->suggestion,
            'code' => $this->code_snippet,
            'severity' => $this->severity,
        ];
    }
    
    public function get_severity_weight(): int
    {
        return match($this->severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            'convention' => 0,
            default => 2,
        };
    }
}