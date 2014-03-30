<?php
if (PHP_VERSION_ID < 50400) {
    interface A extends Iterator, ArrayAccess { }
} else {
    interface A extends Iterator, ArrayAccess, JsonSerializable { }
}
