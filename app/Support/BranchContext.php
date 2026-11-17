<?php

namespace App\Support;

use App\Models\Branch;

class BranchContext
{
    protected ?Branch $branch = null;

    public function set(?Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->branch?->id;
    }

    public function idOrFail(): int
    {
        abort_if($this->branch === null, 400, 'A branch context is required for this operation.');

        return $this->branch->id;
    }

    public function has(): bool
    {
        return $this->branch !== null;
    }
}
