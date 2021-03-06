<?php
declare(strict_types = 1);

namespace PublicWhip\Services;

use Psr\Log\LoggerInterface;
use PublicWhip\Entities\DivisionEntity;
use PublicWhip\Exceptions\Services\BadDatabaseReturnException;
use PublicWhip\Factories\DateTimeFactoryInterface;
use PublicWhip\Factories\EntityFactoryInterface;
use PublicWhip\Providers\DatabaseProviderInterface;
use PublicWhip\Providers\WikiParserProviderInterface;

/**
 * Reads/writes divisions.
 */
final class DivisionService implements DivisionServiceInterface
{
    /**
     *  The database layer.
     *
     * @var DatabaseProviderInterface $databaseProvider
     */
    private $databaseProvider;

    /**
     * The wiki code parser.
     *
     * @var WikiParserProviderInterface $wikiParser
     */
    private $wikiParser;

    /**
     *  The logger.
     *
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     *  The entity factory.
     *
     * @var EntityFactoryInterface
     */
    private $entityFactory;

    /**
     *  DateTime factory.
     *
     * @var DateTimeFactoryInterface
     */
    private $dateTimeFactory;

    /**
     * Setup the division service.
     *
     * @param DatabaseProviderInterface $databaseProvider Database connection layer.
     * @param EntityFactoryInterface $entityFactory Entity factory.
     * @param DateTimeFactoryInterface $dateTimeFactory Date time factory.
     * @param WikiParserProviderInterface $wikiParser Wiki parser.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        DatabaseProviderInterface $databaseProvider,
        EntityFactoryInterface $entityFactory,
        DateTimeFactoryInterface $dateTimeFactory,
        WikiParserProviderInterface $wikiParser,
        LoggerInterface $logger
    ) {
        $this->databaseProvider = $databaseProvider;
        $this->entityFactory = $entityFactory;
        $this->dateTimeFactory = $dateTimeFactory;
        $this->wikiParser = $wikiParser;
        $this->logger = $logger;
    }

    /**
     * Get the latest division date (usually used to ensure data is up to date).
     *
     * @return string In YYYY-MM-DD format.
     */
    public function getNewestDivisionDate(): string
    {
        return (string)$this->databaseProvider->table('pw_division')->max('division_date');
    }

    /**
     * Find a division by its numerical id.
     *
     * @param int $divisionId Numerical id of the division.
     * @return DivisionEntity|null Null if not found.
     */
    public function findByDivisionId(int $divisionId): ?DivisionEntity
    {
        /**
         * Get the basic division data.
         *
         * @var object|null $basicDivision
         */
        $basicDivision = $this->databaseProvider->table('pw_division')
            ->where('division_id', '=', $divisionId)
            ->first();

        if (null === $basicDivision) {
            $this->logger->debug(
                __METHOD__ . ': Did not find by id {id}',
                [
                    'id' => $divisionId
                ]
            );

            return null;
        }

        return $this->buildDivisionEntityFromDivisionTable($basicDivision);
    }

    /**
     * Build/fill out a division and create a division entity.
     *
     * This is a bit of a mess as it needs to reverse engineer whatever PublicWhip v1
     * has done in the database.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @param object $basicDivision A division object as returned from the database.
     * @return DivisionEntity The generated entity.
     * @throws BadDatabaseReturnException
     */
    private function buildDivisionEntityFromDivisionTable(object $basicDivision): DivisionEntity
    {
        if (!property_exists($basicDivision, 'division_id')
            || !property_exists($basicDivision, 'division_date')
            || !property_exists($basicDivision, 'division_number')
            || !property_exists($basicDivision, 'source_url')
            || !property_exists($basicDivision, 'motion')
            || !property_exists($basicDivision, 'division_name')
            || !property_exists($basicDivision, 'debate_url')
            || !property_exists($basicDivision, 'house')
        ) {
            throw new BadDatabaseReturnException('Not a division');
        }

        $builtData = [
            'divisionId' => (int)$basicDivision->division_id,
            'date' => $this->dateTimeFactory->dateTimeImmutableFromYyyyMmDd($basicDivision->division_date),
            'number' => (int)$basicDivision->division_number,
            'sourceUrl' => (string)$basicDivision->source_url,
            'originalMotionText' => (string)$basicDivision->motion,
            'originalMotionTitle' => (string)$basicDivision->division_name,
            'debateUrl' => (string)$basicDivision->debate_url,
            'house' => (string)$basicDivision->house
        ];

        /**
         * Get the vote information
         *
         * If this is an object it should have the following properties
         * division_id, rebellions, tells, turnout, possible_turnout and aye_majority
         *
         * @var object|null
         */
        $voteInformation = $this->databaseProvider->table('pw_cache_divinfo')
            ->where('division_id', '=', $basicDivision->division_id)
            ->first();

        if ($voteInformation) {
            if (!property_exists($voteInformation, 'rebellions')
                || !property_exists($voteInformation, 'turnout')
                || !property_exists($voteInformation, 'possible_turnout')
                || !property_exists($voteInformation, 'aye_majority')
            ) {
                throw new BadDatabaseReturnException('Not a divinfo');
            }

            $builtData['rebellions'] = (int)$voteInformation->rebellions;
            $builtData['turnout'] = (int)$voteInformation->turnout;
            $builtData['possibleTurnout'] = (int)$voteInformation->possible_turnout;
            $builtData['ayeMajority'] = (int)$voteInformation->aye_majority;
        }

        /**
         * Get the edited description for the division.
         *
         * If this is an object it should have the following properties
         * wiki_id, text_body, user_id, edit_date, division_date, division_number and house
         *
         * @var object|null
         */
        $descriptionData = $this->databaseProvider->table('pw_dyn_wiki_motion')
            ->where('division_date', '=', $basicDivision->division_date)
            ->where('division_number', '=', $basicDivision->division_number)
            ->where('house', '=', $basicDivision->house)
            ->orderBy('wiki_id', 'DESC')
            ->first();
        $wikiTextBody = '';

        if ($descriptionData) {
            if (!property_exists($descriptionData, 'text_body')) {
                throw new BadDatabaseReturnException('Not a wiki_motion');
            }

            $wikiTextBody = $descriptionData->text_body;
        }

        /**
         * Now to extract the additional fields.
         */
        $builtData['motionTitle'] = $this->wikiParser->parseDivisionTitle(
            $wikiTextBody,
            $basicDivision->division_name
        );
        $builtData['motionText'] = $this->wikiParser->parseMotionText(
            $wikiTextBody,
            (string)$basicDivision->motion
        );

        return $this->entityFactory->division($builtData);
    }

    /**
     * Find a division by the house, date and division number.
     *
     * @param string $house Name of the house - probably 'commons','lords' or 'scotland'
     * @param string $date Date in YYYY-MM-DD of the division.
     * @param int $divisionNumber Number of the division.
     * @return DivisionEntity|null Entity if found.
     */
    public function findByHouseDateAndNumber(string $house, string $date, int $divisionNumber): ?DivisionEntity
    {
        /**
         * Get the basic division data.
         *
         * @var object|null $basicDivision
         */
        $basicDivision = $this->databaseProvider->table('pw_division')
            ->where('division_date', '=', $date)
            ->where('division_number', '=', $divisionNumber)
            ->where('house', '=', $house)
            ->first();

        if (null === $basicDivision) {
            $this->logger->debug(
                __METHOD__ . ': Did not find in {house} date {date} {divisionNumber}',
                [
                    'house' => $house,
                    'date' => $date,
                    'divisionNumber' => $divisionNumber
                ]
            );

            return null;
        }

        return $this->buildDivisionEntityFromDivisionTable($basicDivision);
    }
}
