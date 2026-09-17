# Identify destinations independently of their URLs

Cross-instance links use persistent destination UUIDs scoped to a logical
instance because readable URLs change when pages are renamed, moved, or served
under another domain. Copies of pages receive new identities, while restoration
of the original page and copies of an environment retain identity; explicit
environment mappings keep those copies from connecting to production peers
automatically. This makes identity lifecycle management part of the extension,
in exchange for links that do not depend on permanent slugs or numerical record
IDs alone.
