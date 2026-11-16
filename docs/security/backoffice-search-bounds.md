# Backoffice search bounds

Authenticated backoffice search requests accept a bounded keyword, ordering
clause and offset before a model builds its SQL query. The factory rejects
keywords longer than 128 bytes, ordering clauses longer than 256 bytes, more
than four ordering terms, ordering terms longer than 64 bytes, and offsets
greater than 100,000. The existing result limit remains capped at 1,000.

These limits reduce repeated `LIKE` work and deep-offset scans while preserving
the normal UI search and pagination ranges. They do not replace endpoint
authorization, relationship scoping, or the model's identifier quoting.

Legacy `backend_api/ajax_filter_*` routes retain their existing redirect and
HTTP-method behavior; bounded JSON validation is asserted on the canonical
search routes after routing.
