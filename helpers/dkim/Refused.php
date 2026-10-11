<?php

namespace EmailSquire\Helpers\Dkim;

/**
 * A task the DKIM helper refuses for good: a crafted or malformed row, an unsafe path, a failed
 * precondition. Never retried. Messages name the domain, the selector or a key's file name at most,
 * never a file's contents or key material.
 */
final class Refused extends \RuntimeException {}
