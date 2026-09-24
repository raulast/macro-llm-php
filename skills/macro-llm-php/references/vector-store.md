# Retrieval

The package could already embed and rerank; it could not retrieve. This is the missing piece, and it is what makes RAG
expressible without reaching for a second library.

## The driver the package ships, and what it costs

`InMemoryVectorStore` keeps vectors in memory and compares them with cosine similarity. It is the reference driver
because **the alternatives cannot be defaults**: `sqlite-vec` and `pgvector` both need an extension that
`composer require` cannot install, and a package whose selling point is that it runs in any PHP 8.1+ application
cannot require either of them.

| | |
| --- | --- |
| Needs | nothing |
| Survives the process | no |
| Search cost | `O(n)` — every vector is compared |
| Good for | a few thousand entries, tests, proving the path end to end |

A production index wants an approximate-nearest-neighbour driver behind the same `VectorStoreInterface`. Nothing in the
retrieval tool couples to this one.

## Storing and searching

```php
use MacroLLM\VectorStore\InMemoryVectorStore;

$store = new InMemoryVectorStore();

$store->add('doc-1', $embedding, ['text' => 'the cat sat', 'lang' => 'en']);

$matches = $store->search(
    query: $queryEmbedding,
    limit: 5,
    minSimilarity: 0.3,          // default 0.0
    filter: ['lang' => 'en'],    // exact matches, ANDed
);

foreach ($matches as $match) {
    // $match->id, $match->score, $match->metadata['text']
}
```

Adding an id twice **replaces** it, so a document can be re-indexed without deleting it first. Removing an id that is
not there is a no-op, for the same reason.

## Letting an Agent search it

A store alone does not make RAG expressible: the model has to be able to ASK for the documents. `SimilaritySearchTool`
is that tool, and it introduces no new concepts — it is an ordinary `ToolDefinition`, offered through the same array
and executed by the same loop.

```php
use MacroLLM\Tool\SimilaritySearchTool;

$tool = SimilaritySearchTool::using(
    store: $store,
    embed: fn (string $query): array => $llm->embed(new EmbeddingRequest([$query]), 'openai')->embeddings[0],
);

$llm->agent(new AgentConfig(tools: [$tool]))->run('What does the handbook say about expenses?');
```

The embedding step is a callable rather than a client, which keeps the tool testable without a provider and usable with
an embedding model this package does not drive.

Its declared schema is ordinary JSON Schema, so the Agent validates a model's arguments against it before the tool runs
— the same guarantee every other tool gets. Asking for more documents than the tool allows is **clamped** rather than
honoured, so a model cannot request the whole index by guessing a large number, and **no matches is a legitimate
answer**: the tool returns an empty list so the model can rephrase or conclude, where an exception would end the turn.

## The guards, and why each exists

Every one of these prevents a wrong ANSWER rather than an error, which is why the store refuses instead of coping:

| Refused | Because |
| --- | --- |
| A vector whose length differs from what is stored | Comparing a 1536-dimension query against 768-dimension entries produces numbers that look like scores and mean nothing. Index a separate store per embedding model. |
| An empty vector | There is nothing to compare; it would score against nothing rather than fail. |
| An all-zero vector | It has no direction, so its cosine similarity is **undefined** — not zero. The usual cause is a provider that failed quietly. |
| A non-numeric component | One bad component makes every score involving that vector meaningless. |

## Similarity, thresholds and what the score means

Cosine similarity ranges from `-1` (opposite) through `0` (unrelated) to `1` (identical direction). Magnitude does not
affect it, so a long vector and a short one pointing the same way score the same.

**The default floor is `0.0`**, which drops unrelated and opposite entries — usually what a retrieval wants. Lower it
explicitly if you need to see negative scores.

Vectors are stored exactly as given. Nothing is normalised on the way in, because quietly transforming a caller's data
is the kind of help that produces surprising scores, and cosine divides the lengths out anyway.
