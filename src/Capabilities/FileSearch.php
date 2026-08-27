<?php

namespace EduLazaro\Laragents\Capabilities;

/**
 * Let the model search files you uploaded to the provider's own store.
 *
 * Not your files: the ones you pushed to them. An app that already indexes its documents
 * (a vector store of its own, a search engine) has this covered and should use a tool,
 * which keeps the documents on your side and the retrieval under your control.
 */
class FileSearch implements Capability
{
    /**
     * @param array<int, string> $storeIds The provider's store ids to search in.
     * @return void
     */
    public function __construct(protected array $storeIds = [])
    {
    }

    /**
     * Its name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'file_search';
    }

    /**
     * Its settings, if any.
     *
     * @return array
     */
    public function options(): array
    {
        return ['store_ids' => $this->storeIds];
    }
}
