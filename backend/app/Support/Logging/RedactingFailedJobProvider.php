<?php

namespace App\Support\Logging;

use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Throwable;

/**
 * Jobs en échec (failed_jobs) : l'exception est enregistrée sous forme texte (message + pile) sans passer par
 * les journaux ; elle est donc expurgée ici avant écriture (docs/security.md §13). Le payload du job n'est pas
 * modifié (il doit rester rejouable) : par conception il ne contient que des identifiants.
 * Toutes les autres opérations sont déléguées au fournisseur d'origine.
 */
final class RedactingFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(
        private readonly FailedJobProviderInterface $inner,
        private readonly SecretRedactor $redactor,
    ) {}

    public function log($connection, $queue, $payload, $exception)
    {
        $text = $exception instanceof Throwable ? $this->redactor->redactThrowableString($exception) : $this->redactor->redactString((string) $exception);

        return $this->inner->log($connection, $queue, $payload, $text);
    }

    public function ids($queue = null)
    {
        return $this->inner->ids($queue);
    }

    public function all()
    {
        return $this->inner->all();
    }

    public function find($id)
    {
        return $this->inner->find($id);
    }

    public function forget($id)
    {
        return $this->inner->forget($id);
    }

    public function flush($hours = null)
    {
        $this->inner->flush($hours);
    }

    public function count($connection = null, $queue = null)
    {
        return $this->inner instanceof CountableFailedJobProvider ? $this->inner->count($connection, $queue) : count($this->inner->all());
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->inner instanceof PrunableFailedJobProvider ? $this->inner->prune($before) : 0;
    }
}
