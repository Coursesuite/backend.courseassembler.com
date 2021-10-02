# backend.courseassembler.com

this is an abstraction layer for connecting to and processing uploaded files, currently connecting through [CloudConvert](https://cloudconvert.com/dashboard/api/v2/keys)'s v2 api.

This prevents the API key from being exposed to end users through client-side code. It also hides the mechanism of file conversion from savvy users who could otherwise simply replicate the site javascript to avoid licencing.

API v1 is deprecated and will be turned off on January 1, 2022.

## installation

run `composer update` to pull the vendor folder
ensure `gzip` compression is enabled for text/plain and application/json
