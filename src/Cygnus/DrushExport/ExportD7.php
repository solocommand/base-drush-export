<?php

namespace Cygnus\DrushExport;

/**
 * Provides support for exporting from Drupal 7
 */
class ExportD7 extends Export
{
    /**
     * {@inheritdoc}
     */
    protected $configs = [
        'arkansas'      => [
            'database'          => 'import_mni_arkansas'
        ],
        'easttn'        => [
            'database'          => 'import_mni_easttn'
        ],
        'mississippi'   => [
            'database'          => 'import_mni_mississippi'
        ],
        'orlando'       => [
            'Taxonomy'  => [
                'Focus Sections'    => 'Taxonomy\\Category',
                'Tags'              => 'Taxonomy\\Tag',
                'Section'           => 'Taxonomy\\Section',
            ],
            'Content'   => [
                'story'         => 'Website\\Content\\Article',
                'manatee_story' => 'Website\\Content\\Article',
                'feed_content'  => 'Website\\Content\\News',
                // 'feed'          => 'Website\\Content\\News',
            ],
            'Section'   => [
                'page'          => 'Website\\Section',
            ],
            'Issue'     => [
                'issue'         => 'Magazine\\Issue',
                'manatee_issue' => 'Magazine\\Issue',
            ],
            'database'          => 'import_mni_orlando'
        ]
    ];

    /**
     * {@inheritdoc}
     */
    protected function getObjects($resource, $type = 'node')
    {
        $results = [];
        foreach ($resource as $row) {
            switch ($type) {
                case 'user':
                    $object = user_load($row->uid);
                    break;

                default:
                    $object = node_load($row->nid);
                    break;
            }
            if (is_object($object)) {
                $results[] = $object;
            }
        }
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRaw($resource)
    {
        return $resource->fetchAll();
    }

    /**
     * {@inheritdoc}
     */
    protected function formatValues(array $types = [])
    {
        return $types;
    }

    /**
     * {@inheritdoc}
     */
    protected function countNodes(array $types = [])
    {
        $types = $this->formatValues($types);
        $inQuery = implode(',', array_fill(0, count($types), '?'));
        $query = 'SELECT count(*) as count from {node} where type in ('.$inQuery.')';
        $resource = db_query($query, $types);
        $count = reset($this->getRaw($resource));
        return (int) $count->count;
    }

    /**
     * {@inheritdoc}
     */
    protected function queryNodes(array $types = [], $limit = 100, $skip = 0)
    {
        $types = $this->formatValues($types);
        $inQuery = implode(',', array_fill(0, count($types), '?'));
        $query = 'SELECT nid, type from {node} where type in ('.$inQuery.') ORDER BY nid asc';
        $resource = db_query_range($query, $skip, $limit, $types);
        $nodes = $this->getObjects($resource, 'node');
        // $this->writeln(sprintf('DEBUG: `%s` with `%s` returned %s results.', $query, $types, count($nodes)));
        return $nodes;
    }

    /**
     * {@inheritdoc}
     */
    protected function getFieldValue($field, $node, $return = null)
    {
        if (null === $field || empty($field)) {
            return $return;
        }
        if (isset($field[$node->language])) {
            return $this->getFieldValue($field[$node->language], $node, $return);
        }
        if (isset($field['und'])) {
            return $this->getFieldValue($field['und'], $node, $return);
        }
        return $field;
    }

    /**
     * {@inheritdoc}
     */
    protected function convertTaxonomy(&$node)
    {
        $taxonomy = [];
        unset($node->taxonomy);

        if (isset($node->field_tags)) {
            $terms = $this->getFieldValue($node->field_tags, $node, []);
            foreach ($terms as $tax) {
                $this->addTerm($taxonomy, $tax['tid']);
            }
            unset($node->field_tags);
        }

        // Handle tagging/primary tag/primary section nonsense
        if (isset($node->field_special_focus)) {
            $terms = $this->getFieldValue($node->field_special_focus, $node, []);
            foreach ($terms as $tax) {
                $this->addTerm($taxonomy, $tax['tid']);
            }
            unset($node->field_special_focus);
        }

        if (isset($node->field_focus_sections)) {
            $terms = $this->getFieldValue($node->field_focus_sections, $node, []);
            foreach ($terms as $tax) {
                $this->addTerm($taxonomy, $tax['tid']);
            }
            unset($node->field_focus_sections);
        }

        if (isset($node->field_section)) {
            $terms = $this->getFieldValue($node->field_section, $node, []);
            foreach ($terms as $tax) {
                $this->addTerm($taxonomy, $tax['tid']);
            }
            unset($node->field_section);
        }

        if (!empty($taxonomy)) {
            $node->taxonomy = $taxonomy;
        }
    }

    protected function addTerm(&$taxonomy, $tid)
    {
        $tax = taxonomy_term_load($tid);
        $v = taxonomy_vocabulary_load($tax->vid);

        if (null !== ($type = (isset($this->map['Taxonomy'][$v->name])) ? $this->map['Taxonomy'][$v->name] : null)) {
            $type = str_replace('Taxonomy\\', '', $type);
            $taxonomy[] = [
                'id'    => (int) $tid,
                'type'  => $type
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function convertFields(&$node)
    {
        $nid = (int) $node->nid;

        $node->_id = $nid;
        unset($node->nid);

        $node->type = str_replace('Website\\Content\\', '', $this->map['Content'][$node->type]);

        $node->name = $node->title;
        unset($node->title);

        $node->status = (int) $node->status;

        $node->createdBy = $node->updatedBy = (int) $node->uid;
        unset($node->uid);

        $node->created = (int) $node->created;
        $node->published = $node->updated = (int) $node->changed;
        unset($node->changed);

        unset($node->rdf_mapping, $node->cid, $node->last_comment_uid, $node->metatags, $node->field_legacy_article_id);

        $node->mutations = [];

        // var_dump($node);
        // die();

        if (isset($node->path)) {
            $node->mutations['Website']['aliases'][] = $node->path;
            unset($node->path);
        }

        $body = $this->getFieldValue($node->body, $node, []);
        if (count($body) === 1) {
            $body = reset($body);
        }
        unset($node->body);
        if (isset($body['value'])) {
            $node->body = $body['value'];
        }
        if (isset($body['summary'])) {
            $node->teaser = $body['summary'];
        }

        if (isset($node->field_byline)) {
            $values = $this->getFieldValue($node->field_byline, $node, []);
            foreach ($values as $key => $value) {
                if (isset($value['value']) && $value['value'] == null) {
                    continue;
                }
                $node->byline = $value['value'];
            }
            unset($node->field_byline);
        }

        if (isset($node->field_deck)) {
            $values = $node->field_deck;
            foreach ($values as $key => $value) {
                if (isset($value['value']) && $value['value'] == null) {
                    continue;
                }
                $node->mutations['Magazine']['deck'] = $value['value'];
            }
            unset($node->field_deck);
        }

        if (isset($node->field_deck_teaser)) {
            $values = $this->getFieldValue($node->field_deck_teaser, $node, []);
            foreach ($values as $key => $value) {
                if (isset($value['value']) && $value['value'] == null) {
                    continue;
                }
                $node->mutations['Magazine']['deck'] = $value['value'];
            }
            unset($node->field_deck_teaser);
        }

        if (isset($node->field_special_focus)) {
            foreach ($node->field_special_focus as $link) {
                if (array_key_exists('value', $link) && null == $link['value']) {
                    continue;
                }

                $node->specialFocus = $link['value'];
                break;
            }
        }
        unset($node->field_special_focus);

        $this->convertFeedData($node);
        $this->buildRelationships($node);
        $this->removeCrapFields($node);

    }

    /**
     * {@inheritdoc}
     */
    protected function buildRelationships(&$node)
    {
        // Handle 'picture' field
        if (!empty($node->picture)) {
            var_dump($node->picture);
            die();
        }
        unset($node->picture);

        // Handle 'files' field
        if (!empty($node->files)) {
            var_dump($node->files);
            die();
        }
        unset($node->files);

        if (isset($node->field_image)) {
            $images = $this->getFieldValue($node->field_image, $node, []);
            foreach ($images as $value) {
                if (0 === (int) $value['fid']) {
                    continue;
                }
                $ref = [
                    'id'    => (int) $value['fid'],
                    'type'  => 'Image'
                ];
                $node->relatedMedia[] = $ref;

                $caption = null;
                if (isset($node->field_image_caption)) {
                    $val = $this->getFieldValue($node->field_image_caption, $node, null);
                    if (null !== $val) {
                        $caption = $val['value'];
                    }
                }
                if (isset($node->field_image_text)) {
                    $val = $this->getFieldValue($node->field_image_text, $node, null);
                    if (null !== $val) {
                        $caption = $val['value'];
                    }
                }

                $this->createImage($value, $caption);

                if (!isset($node->primaryImage)) {
                    $node->primaryImage = $ref['id'];
                }
            }
        }
        unset($node->field_image, $node->field_image_text, $node->field_image_caption);

    }
}