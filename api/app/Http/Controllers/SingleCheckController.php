<?php

namespace App\Http\Controllers;

use App\Enums\CheckType;
use App\Enums\ItemStatus;
use App\Http\Requests\SingleCheckRequest;
use App\Http\Resources\CheckItemResource;
use App\Models\CheckItem;
use App\Services\DomainCheck\DomainCheckRunner;

class SingleCheckController extends Controller
{
    /**
     * Run one check synchronously and return the result right away.
     *
     * The response has the same shape as a bulk item so the UI handles both alike.
     */
    public function __invoke(SingleCheckRequest $request, CheckType $type, DomainCheckRunner $runner): CheckItemResource
    {
        $domain = (string) $request->domain();
        $options = $request->checkOptions();
        $outcome = $runner->run($type, $domain, $options);

        return new CheckItemResource(new CheckItem([
            'input' => $request->input('input'),
            'domain' => $domain,
            'selector' => $options['selectors'][0] ?? null,
            'status' => $outcome['failure'] === null ? ItemStatus::Completed : ItemStatus::Failed,
            'result' => $outcome['result'],
            'error' => $outcome['failure'],
            'duration_ms' => $outcome['duration_ms'],
        ]));
    }
}
