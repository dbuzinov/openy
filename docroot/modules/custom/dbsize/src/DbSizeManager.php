<?php

namespace Drupal\dbsize;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Class DbSizeManager.
 */
class DbSizeManager implements DbSizeManagerInterface {

  /**
   * Connection.
   *
   * @var Connection
   */
  protected $connection;

  /**
   * Entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * DbSizeTable constructor.
   *
   * @param Connection $connection
   *   The DB connection.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager.
   * @param LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(Connection $connection, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, LoggerChannelInterface $logger) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function getTablesSize(array $tables) {
    if (empty($tables)) {
      return FALSE;
    }

    $options = $this->connection->getConnectionOptions();

    $q = 'SELECT * FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = :db';
    $result = $this->connection->query($q, [':db' => $options['database']]);

    $length = 0;
    foreach ($result as $table) {
      if (in_array($table->TABLE_NAME, $tables)) {
        $length += $table->DATA_LENGTH + $table->INDEX_LENGTH;
      }
    }

    return empty($length) ? FALSE : $length;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTables($entity_type_id) {
    $tables = [];

    try {
      $type = $this->entityTypeManager->getDefinition($entity_type_id);
    }
    catch (\Exception $e) {
      $msg = 'Failed to get definition for entity type id %id with message: %msg.';
      $this->logger->warning(
        $msg,
        [
          '%id' => $entity_type_id,
          '%msg' => $e->getMessage(),
        ]
      );

      return FALSE;
    }

    $revisionable = FALSE;

    // Add base table.
    $tables[] = $type->getBaseTable();

    // Add data table.
    if ($type->getDataTable()) {
      $tables[] = $type->getDataTable();
    }

    // Add revision tables.
    if ($type->isRevisionable()) {
      $revisionable = TRUE;
      if ($type->getRevisionTable()) {
        $tables[] = $type->getRevisionTable();
      }

      if ($type->getRevisionDataTable()) {
        $tables[] = $type->getRevisionDataTable();
      }
    }

    // Get all fields.
    $fields = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);

    foreach ($fields as $field) {
      if (!$field->isBaseField()) {
        // @todo Find proper way to get table names?
        // @todo Find proper way to get tables with revisions?
        // @todo The table name will be invalid for very long names.
        $tables[] =
          $field->getTargetEntityTypeId() . '__' . $field->getName();
        if ($revisionable) {
          $tables[] = $field->getTargetEntityTypeId() . '_revision__' . $field->getName();
        }
      }
    }

    return $tables;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntitySize($entity_type_id) {
    $tables = $this->getEntityTables($entity_type_id);
    return $this->getTablesSize($tables);
  }

  /**
   * {@inheritdoc}
   */
  public function convertEntityTablesEngine($entity_type_id, $engine) {
    $tables = $this->getEntityTables($entity_type_id);
    foreach ($tables as $table) {
      $q = "ALTER TABLE {$table} ENGINE = :engine";
      $result = $this->connection->query(
        $q,
        [
          ':engine' => $engine,
        ]
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function repairEntityTables($entity_type_id) {
    $tables = $this->getEntityTables($entity_type_id);
    foreach ($tables as $table) {
      $q = "REPAIR TABLE {$table}";
      $result = $this->connection->query($q);
    }
  }

}
