<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\FilterManagerBundle\Filter\Widget\Dynamic;

use ONGR\ElasticsearchBundle\Result\Aggregation\AggregationValue;
use ONGR\ElasticsearchDSL\Aggregation\FilterAggregation;
use ONGR\ElasticsearchDSL\Aggregation\NestedAggregation;
use ONGR\ElasticsearchDSL\Aggregation\TermsAggregation;
use ONGR\ElasticsearchDSL\BuilderInterface;
use ONGR\ElasticsearchDSL\Query\BoolQuery;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Query\NestedQuery;
use ONGR\ElasticsearchDSL\Query\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchBundle\Result\DocumentIterator;
use ONGR\FilterManagerBundle\Filter\FilterState;
use ONGR\FilterManagerBundle\Filter\Helper\SortAwareTrait;
use ONGR\FilterManagerBundle\Filter\Helper\ViewDataFactoryInterface;
use ONGR\FilterManagerBundle\Filter\ViewData\AggregateViewData;
use ONGR\FilterManagerBundle\Filter\ViewData;
use ONGR\FilterManagerBundle\Filter\Widget\AbstractFilter;
use ONGR\FilterManagerBundle\Search\SearchRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * This class provides single terms choice.
 */
class DynamicAggregate extends AbstractFilter implements ViewDataFactoryInterface
{
    use SortAwareTrait;

    /**
     * @return string
     */
    public function getNameField()
    {
        return $this->getOption('name_field', false);
    }

    /**
     * @return bool
     */
    public function getShowZeroChoices()
    {
        return $this->getOption('show_zero_choices', false);
    }

    /**
     * {@inheritdoc}
     */
    public function getState(Request $request)
    {
        $state = new FilterState();
        $value = $request->get($this->getRequestField());

        if (isset($value) && is_array($value)) {
            $state->setActive(true);
            $state->setValue($value);
            $state->setUrlParameters([$this->getRequestField() => $value]);
        }

        return $state;
    }

    /**
     * {@inheritdoc}
     */
    public function modifySearch(Search $search, FilterState $state = null, SearchRequest $request = null)
    {
        list($path, $field) = explode('>', $this->getDocumentField());

        if ($state && $state->isActive()) {
            $boolQuery = new BoolQuery();
            foreach ($state->getValue() as $groupName => $value) {
                $innerBoolQuery = new BoolQuery();
                $nestedQuery = new NestedQuery($path, $innerBoolQuery);
                $innerBoolQuery->add(
                    new TermQuery($field, $value)
                );
                $innerBoolQuery->add(
                    new TermQuery($this->getNameField(), $groupName)
                );
                $boolQuery->add($nestedQuery);
            }
            $search->addPostFilter($boolQuery);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function preProcessSearch(Search $search, Search $relatedSearch, FilterState $state = null)
    {
        list($path, $field) = explode('>', $this->getField());
        $filter = !empty($filter = $relatedSearch->getPostFilters()) ? $filter : new MatchAllQuery();
        $aggregation = new NestedAggregation($state->getName(), $path);
        $nameAggregation = new TermsAggregation('name', $this->getNameField());
        $valueAggregation = new TermsAggregation('value', $field);
        $filterAggregation = new FilterAggregation($state->getName() . '-filter');
        $nameAggregation->addAggregation($valueAggregation);
        $aggregation->addAggregation($nameAggregation);
        $filterAggregation->setFilter($filter);

        if ($this->getSortType()) {
            $valueAggregation->addParameter('order', [$this->getSortType()['type'] => $this->getSortType()['order']]);
        }

        if ($state->isActive()) {
            foreach ($state->getValue() as $key => $term) {
                $terms = $state->getValue();
                unset($terms[$key]);

                $this->addSubFilterAggregation(
                    $filterAggregation,
                    $aggregation,
                    $terms,
                    $key
                );
            }
        }

        $this->addSubFilterAggregation(
            $filterAggregation,
            $aggregation,
            $state->getValue() ? $state->getValue() : [],
            'all-selected'
        );

        $search->addAggregation($filterAggregation);

        if ($this->getShowZeroChoices()) {
            $search->addAggregation($aggregation);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createViewData()
    {
        return new AggregateViewData();
    }

    /**
     * {@inheritdoc}
     */
    public function getViewData(DocumentIterator $result, ViewData $data)
    {
        $unsortedChoices = [];
        $activeNames = $data->getState()->isActive() ? array_keys($data->getState()->getValue()) : [];
        $filterAggregations = $this->fetchAggregation($result, $data->getName(), $data->getState()->getValue());

        if ($this->getShowZeroChoices()) {
            $unsortedChoices = $this->formInitialUnsortedChoices($result, $data);
        }

        /** @var AggregationValue $bucket */
        foreach ($filterAggregations as $activeName => $aggregation) {
            foreach ($aggregation as $bucket) {
                $name = $bucket['key'];

                if ($name != $activeName && $activeName != 'all-selected') {
                    continue;
                }

                $this->addNonZeroValuesToUnsortedChoices($unsortedChoices, $activeName, $bucket, $data);
            }
        }

        foreach ($unsortedChoices['all-selected'] as $name => $buckets) {
            if (in_array($name, $activeNames)) {
                continue;
            }

            $unsortedChoices[$name] = $buckets;
        }

        unset($unsortedChoices['all-selected']);
        ksort($unsortedChoices);

        /** @var AggregateViewData $data */
        foreach ($unsortedChoices as $name => $choices) {
            $choiceViewData = new ViewData\ChoicesAwareViewData();
            $choiceViewData->setName($name);
            $choiceViewData->setChoices($choices);
            $choiceViewData->setUrlParameters([]);
            $choiceViewData->setResetUrlParameters($data->getResetUrlParameters());
            $data->addItem($choiceViewData);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function isRelated()
    {
        return true;
    }

    /**
     * Fetches buckets from search results.
     *
     * @param DocumentIterator $result     Search results.
     * @param string           $filterName Filter name.
     * @param array            $values     Values from the state object
     *
     * @return array Buckets.
     */
    protected function fetchAggregation(DocumentIterator $result, $filterName, $values)
    {
        $data = [];
        $values = empty($values) ? [] : $values;
        $aggregation = $result->getAggregation(sprintf('%s-filter', $filterName));

        foreach ($values as $name => $value) {
            $data[$name] = $aggregation->find(sprintf('%s.%s.name', $name, $filterName));
        }

        $data['all-selected'] = $aggregation->find(sprintf('all-selected.%s.name', $filterName));

        return $data;
    }

    /**
     * A method used to add an additional filter to the aggregations
     * in preProcessSearch
     *
     * @param FilterAggregation $filterAggregation
     * @param NestedAggregation $deepLevelAggregation
     * @param array             $terms Terms of additional filter
     * @param string            $aggName
     *
     * @return BuilderInterface
     */
    protected function addSubFilterAggregation(
        $filterAggregation,
        &$deepLevelAggregation,
        $terms,
        $aggName
    ) {
        list($path, $field) = explode('>', $this->getDocumentField());
        $boolQuery = new BoolQuery();

        foreach ($terms as $groupName => $term) {
            $nestedBoolQuery = new BoolQuery();
            $nestedBoolQuery->add(new TermQuery($field, $term));
            $nestedBoolQuery->add(new TermQuery($this->getNameField(), $groupName));
            $boolQuery->add(new NestedQuery($path, $nestedBoolQuery));
        }

        $boolQuery = !empty($boolQuery->getQueries()) ? $boolQuery : new MatchAllQuery();
        $innerFilterAggregation = new FilterAggregation($aggName, $boolQuery);
        $innerFilterAggregation->addAggregation($deepLevelAggregation);
        $filterAggregation->addAggregation($innerFilterAggregation);
    }

    /**
     * @param string   $key
     * @param string   $name
     * @param ViewData $data
     * @param bool     $active True when the choice is active
     *
     * @return array
     */
    protected function getOptionUrlParameters($key, $name, ViewData $data, $active)
    {
        $value = $data->getState()->getValue();
        $parameters = $data->getResetUrlParameters();

        if (!empty($value)) {
            if ($active) {
                unset($value[array_search($key, $value)]);
                $parameters[$this->getRequestField()] = $value;

                return $parameters;
            }

            $parameters[$this->getRequestField()] = $value;
        }

        $parameters[$this->getRequestField()][$name] = $key;

        return $parameters;
    }

    /**
     * Returns whether choice with the specified key is active.
     *
     * @param string   $key
     * @param ViewData $data
     * @param string   $activeName
     *
     * @return bool
     */
    protected function isChoiceActive($key, ViewData $data, $activeName)
    {
        return $data->getState()->isActive() && in_array($key, $data->getState()->getValue());
    }

    /**
     * Forms $unsortedChoices array with all possible choices.
     * 0 is assigned to the document count of the choices.
     *
     * @param DocumentIterator $result
     * @param ViewData         $data
     *
     * @return array
     */
    protected function formInitialUnsortedChoices($result, $data)
    {
        $unsortedChoices = [];
        $urlParameters = array_merge(
            $data->getResetUrlParameters(),
            $data->getState()->getUrlParameters()
        );

        foreach ($result->getAggregation($data->getName())->getAggregation('name') as $nameBucket) {
            $groupName = $nameBucket['key'];

            foreach ($nameBucket->getAggregation('value') as $bucket) {
                $choice = new ViewData\Choice();
                $choice->setActive(false);
                $choice->setUrlParameters($urlParameters);
                $choice->setLabel($bucket['key']);
                $choice->setCount(0);
                $unsortedChoices[$groupName][$bucket['key']] = $choice;
                $unsortedChoices['all-selected'][$groupName][$bucket['key']] = $choice;
            }
        }

        return $unsortedChoices;
    }

    /**
     * @param array            $unsortedChoices
     * @param string           $activeName
     * @param AggregationValue $agg
     * @param ViewData         $data
     */
    protected function addNonZeroValuesToUnsortedChoices(&$unsortedChoices, $activeName, $agg, $data) {
        $name = $agg['key'];
        foreach ($agg['value']['buckets'] as $bucket) {
            $active = $this->isChoiceActive($bucket['key'], $data, $activeName);
            $choice = new ViewData\Choice();
            $choice->setLabel($bucket['key']);
            $choice->setCount($bucket['doc_count']);
            $choice->setActive($active);

            $choice->setUrlParameters(
                $this->getOptionUrlParameters($bucket['key'], $name, $data, $active)
            );

            if ($activeName == 'all-selected') {
                $unsortedChoices[$activeName][$name][$bucket['key']] = $choice;
            } else {
                $unsortedChoices[$activeName][$bucket['key']] = $choice;
            }
        }
    }
}
