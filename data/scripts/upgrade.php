<?php
namespace Ead;

/**
 * @var Module $this
 * @var \Zend\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $oldVersion
 * @var string $newVersion
 */
$services = $serviceLocator;

/**
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var array $config
 * @var \Omeka\Mvc\Controller\Plugin\Api $api
 */
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');

if (version_compare($oldVersion, '3.0.2', '<')) {
    // Add the property "ead:ead".
    $vocabulary = $api
        ->searchOne('vocabularies', ['prefix' => 'ead'])->getContent();
    $vocabularyId = $vocabulary->id();
    $ownerId = $vocabulary->owner()->id();
    $sql = <<<SQL
INSERT INTO property
(owner_id, vocabulary_id, local_name, label, comment)
VALUES
($ownerId, $vocabularyId, "ead", "Is EAD archive", "Indicates that the resource is an archive managed with the standard EAD.")
;
SQL;
    $connection->exec($sql);
}
