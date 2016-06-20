<?php

namespace Drupal\ymca_retention\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\ymca_retention\MemberInterface;
use Drupal\Core\Entity\Entity;

/**
 * Defines the ContentEntityExample entity.
 *
 * @ingroup content_entity_example
 *
 * @ContentEntityType(
 *   id = "ymca_retention_member",
 *   label = @Translation("Member entity"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\ymca_retention\Entity\Controller\MemberListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\ymca_retention\Form\MemberForm",
 *       "add" = "Drupal\ymca_retention\Form\MemberForm",
 *       "edit" = "Drupal\ymca_retention\Form\MemberForm",
 *       "delete" = "Drupal\ymca_retention\Form\MemberDeleteForm",
 *     },
 *     "access" = "Drupal\ymca_retention\EntityAccess\MemberAccessControlHandler",
 *   },
 *   base_table = "ymca_retention_member",
 *   admin_permission = "administer ymca_retention_member entity",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "membership_id",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/ymca-retention-member/{ymca_retention_member}",
 *     "edit-form" = "/admin/structure/ymca-retention-member/{ymca_retention_member}/edit",
 *     "delete-form" = "/admin/structure/ymca-retention-member/{ymca_retention_member}/delete",
 *     "collection" = "/admin/structure/ymca-retention-member/list"
 *   },
 * )
 */
class Member extends ContentEntityBase implements MemberInterface {

  /**
   * {@inheritdoc}
   *
   * Define the field properties here.
   *
   * Field name, type and size determine the table structure.
   *
   * In addition, we can define how the field and its content can be manipulated
   * in the GUI. The behaviour of the widgets used can be determined here.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    // Standard field, used as unique if primary index.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Membership entity.'))
      ->setReadOnly(TRUE);

    $fields['mail'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Email'))
      ->setDescription(t('The email of this user.'))
      ->setDefaultValue('')
      ->addConstraint('UserMailUnique')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string',
        'weight' => -6,
      ])
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // MembershipID field for the contact.
    $fields['membership_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Membership ID'))
      ->setDescription(t('The id on the membership card.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the record was created.'));

    $fields['points'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Points'))
      ->setDescription(t('Points of the Membership entity.'))
      ->setDefaultValue(0);

    $fields['first_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('First name'))
      ->setDefaultValue('')
      ->setDescription(t('Member first name.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string',
        'weight' => -3,
      ])
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Last name'))
      ->setDescription(t('Member last name.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string',
        'weight' => -3,
      ])
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['branch'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Branch ID'))
      ->setDescription(t('Member branch ID.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ]);

    $fields['total_visits'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Visits'))
      ->setDescription(t('Number of visits.'))
      ->setDefaultValue(0);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail() {
    return $this->get('mail')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setEmail($mail) {
    $this->set('mail', $mail);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMemberId() {
    return $this->get('membership_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMemberId($member_id) {
    $this->set('membership_id', $member_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPoints() {
    return $this->get('points')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPoints($value) {
    $this->set('points', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFirstName() {
    return $this->get('first_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setFirstName($value) {
    $this->set('first_name', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastName() {
    return $this->get('last_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setLastName($value) {
    $this->set('last_name', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFullName() {
    $name = $this->getFirstName();
    $name .= ' ';
    $name .= $this->getLastName();

    return $name;
  }

  /**
   * {@inheritdoc}
   */
  public function getBranchId() {
    return $this->get('branch')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setBranchId($value) {
    $this->set('branch', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVisits() {
    return $this->get('total_visits')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setVisits($value) {
    $this->set('total_visits', $value);
    return $this;
  }

}
