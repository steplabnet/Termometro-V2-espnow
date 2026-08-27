<?php
/**
 * Shared secret between mqtt_ingest.py and ingest.php.
 *
 * The bridge sends it in the X-Ingest-Token header; ingest.php refuses anything
 * else. Change it here and in the service's INGEST_TOKEN at the same time.
 */
return '4945cbdbe95752de9c6c6ae67118f10df884d6f87eb8ae16';
