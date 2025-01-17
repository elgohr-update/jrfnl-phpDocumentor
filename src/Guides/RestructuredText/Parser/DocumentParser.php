<?php

declare(strict_types=1);

namespace phpDocumentor\Guides\RestructuredText\Parser;

use ArrayObject;
use Doctrine\Common\EventManager;
use phpDocumentor\Guides\Environment;
use phpDocumentor\Guides\Nodes\AnchorNode;
use phpDocumentor\Guides\Nodes\CodeNode;
use phpDocumentor\Guides\Nodes\DocumentNode;
use phpDocumentor\Guides\Nodes\Node;
use phpDocumentor\Guides\Nodes\ParagraphNode;
use phpDocumentor\Guides\Nodes\SectionBeginNode;
use phpDocumentor\Guides\Nodes\SectionEndNode;
use phpDocumentor\Guides\Nodes\SeparatorNode;
use phpDocumentor\Guides\Nodes\SpanNode;
use phpDocumentor\Guides\Nodes\TitleNode;
use phpDocumentor\Guides\RestructuredText\Directives\Directive;
use phpDocumentor\Guides\RestructuredText\Event\PostParseDocumentEvent;
use phpDocumentor\Guides\RestructuredText\Event\PreParseDocumentEvent;
use phpDocumentor\Guides\RestructuredText\Parser;
use phpDocumentor\Guides\RestructuredText\Parser\Directive as ParserDirective;
use RuntimeException;
use Throwable;

use function array_search;
use function chr;
use function explode;
use function md5;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

class DocumentParser
{
    /** @var Parser */
    private $parser;

    /** @var Environment */
    private $environment;

    /** @var EventManager */
    private $eventManager;

    /** @var Directive[] */
    private $directives;

    /** @var DocumentNode */
    private $document;

    /** @var false|string|null */
    private $specialLetter;

    /** @var ParserDirective|null */
    private $directive;

    /** @var LineDataParser */
    private $lineDataParser;

    /** @var LineChecker */
    private $lineChecker;

    /** @var TableParser */
    private $tableParser;

    /** @var Buffer */
    private $buffer;

    /** @var Node|null */
    private $nodeBuffer;

    /** @var bool */
    private $isCode = false;

    /** @var Lines */
    private $lines;

    /** @var string */
    private $state;

    /** @var TitleNode */
    private $lastTitleNode;

    /** @var ArrayObject<int, TitleNode> */
    private $openTitleNodes;

    /** @var Subparsers\Subparser|null */
    private $subparser;

    /**
     * @param Directive[] $directives
     */
    public function __construct(
        Parser $parser,
        EventManager $eventManager,
        array $directives
    ) {
        $this->parser = $parser;
        $this->environment = $parser->getEnvironment();
        $this->eventManager = $eventManager;
        $this->directives = $directives;
        $this->lineDataParser = new LineDataParser($this->parser, $eventManager);
        $this->lineChecker = new LineChecker($this->lineDataParser);
        $this->tableParser = new TableParser();
        $this->buffer = new Buffer();
        $this->openTitleNodes = new ArrayObject();
    }

    public function getDocument(): DocumentNode
    {
        return $this->document;
    }

    public function parse(string $contents): DocumentNode
    {
        $preParseDocumentEvent = new PreParseDocumentEvent($this->parser, $contents);

        $this->eventManager->dispatchEvent(
            PreParseDocumentEvent::PRE_PARSE_DOCUMENT,
            $preParseDocumentEvent
        );

        $this->document = new DocumentNode(md5($contents));

        $this->init();

        $this->parseLines(trim($preParseDocumentEvent->getContents()));

        foreach ($this->directives as $directive) {
            $directive->finalize($this->document);
        }

        $this->eventManager->dispatchEvent(
            PostParseDocumentEvent::POST_PARSE_DOCUMENT,
            new PostParseDocumentEvent($this->document)
        );

        return $this->document;
    }

    private function init(): void
    {
        $this->specialLetter = false;
        $this->buffer = new Buffer();
        $this->nodeBuffer = null;
    }

    private function setState(string $state): void
    {
        $this->state = $state;
        $this->subparser = null;

        switch ($state) {
            case State::TITLE:
                // The amount of state being passed to the TitleParser is questionable. But to keep it simple for now,
                // we keep it like this.
                $this->subparser = new Subparsers\TitleParser(
                    $this->parser,
                    $this->eventManager,
                    $this->buffer,
                    $this->specialLetter,
                    $this->lastTitleNode,
                    $this->document,
                    $this->openTitleNodes
                );
                break;
            case State::LIST:
                $this->subparser = new Subparsers\ListParser($this->parser, $this->eventManager);
                break;
            case State::DEFINITION_LIST:
                $this->subparser = new Subparsers\DefinitionListParser(
                    $this->parser,
                    $this->eventManager,
                    $this->buffer,
                    $this->lines
                );
                break;
            case State::COMMENT:
                $this->subparser = new Subparsers\CommentParser($this->parser, $this->eventManager);
                break;
            case State::BLOCK:
                $this->subparser = new Subparsers\BlockParser($this->parser, $this->eventManager, $this->buffer);
                break;
            case State::CODE:
                $this->subparser = new Subparsers\CodeParser($this->parser, $this->eventManager, $this->buffer);
                break;
        }
    }

    private function prepareDocument(string $document): string
    {
        $document = str_replace("\r\n", "\n", $document);
        $document = sprintf("\n%s\n", $document);

        $document = $this->mergeIncludedFiles($document);

        // Removing UTF-8 BOM
        $document = str_replace("\xef\xbb\xbf", '', $document);

        // Replace \u00a0 with " "
        $document = str_replace(chr(194) . chr(160), ' ', $document);

        return $document;
    }

    private function createLines(string $document): Lines
    {
        return new Lines(explode("\n", $document));
    }

    private function parseLines(string $document): void
    {
        $document = $this->prepareDocument($document);

        $this->lines = $this->createLines($document);
        $this->setState(State::BEGIN);

        foreach ($this->lines as $line) {
            while (true) {
                if ($this->parseLine($line)) {
                    break;
                }
            }
        }

        // DocumentNode is flushed twice to trigger the directives
        $this->flush();
        $this->flush();

        foreach ($this->openTitleNodes as $titleNode) {
            $this->endOpenSection($titleNode);
        }
    }

    private function parseLine(string $line): bool
    {
        switch ($this->state) {
            case State::BEGIN:
                if (trim($line) !== '') {
                    if ($this->lineChecker->isListLine($line, $this->isCode)) {
                        $this->setState(State::LIST);

                        return false;
                    }

                    if ($this->lineChecker->isBlockLine($line)) {
                        if ($this->isCode) {
                            $this->setState(State::CODE);
                        } else {
                            $this->setState(State::BLOCK);
                        }

                        return false;
                    }

                    if ($this->parseLink($line)) {
                        return true;
                    }

                    if ($this->lineChecker->isDirective($line)) {
                        $this->setState(State::DIRECTIVE);
                        $this->buffer->clear();
                        $this->flush();
                        $this->initDirective($line);
                    } elseif ($this->lineChecker->isDefinitionList($this->lines->getNextLine())) {
                        $this->setState(State::DEFINITION_LIST);
                        $this->buffer->push($line);

                        return true;
                    } else {
                        $separatorLineConfig = $this->tableParser->parseTableSeparatorLine($line);

                        if ($separatorLineConfig === null) {
                            $this->setState(State::NORMAL);

                            return false;
                        }

                        $this->setState(State::TABLE);
                        $this->subparser = new Subparsers\TableParser(
                            $this->parser,
                            $this->eventManager,
                            $separatorLineConfig,
                            $line
                        );
                    }
                }

                break;

            case State::NORMAL:
                if (trim($line) !== '') {
                    $specialLetter = $this->lineChecker->isSpecialLine($line);

                    if ($specialLetter !== null) {
                        $this->specialLetter = $specialLetter;

                        $lastLine = $this->buffer->pop();

                        if ($lastLine !== null) {
                            $this->buffer = new Buffer([$lastLine]);
                            $this->setState(State::TITLE);
                        } else {
                            $this->buffer->push($line);
                            $this->setState(State::SEPARATOR);
                        }

                        $this->flush();
                        $this->setState(State::BEGIN);
                    } elseif ($this->lineChecker->isDirective($line)) {
                        $this->flush();
                        $this->setState(State::BEGIN);

                        return false;
                    } elseif ($this->lineChecker->isComment($line)) {
                        $this->flush();
                        $this->setState(State::COMMENT);
                    } else {
                        $this->buffer->push($line);
                    }
                } else {
                    $this->flush();
                    $this->setState(State::BEGIN);
                }

                break;

            case State::BLOCK:
            case State::CODE:
            case State::LIST:
            case State::DEFINITION_LIST:
                if (!$this->subparser->parse($line)) {
                    $this->flush();
                    $this->setState(State::BEGIN);

                    return false;
                }

                break;

            case State::TABLE:
                if (!$this->subparser->parse($line)) {
                    $this->flush();
                    $this->setState(State::BEGIN);

                    // TODO: No return?
                }

                break;

            case State::COMMENT:
                if (!$this->subparser->parse($line)) {
                    $this->setState(State::BEGIN);

                    return false;
                }

                break;

            case State::DIRECTIVE:
                if (!$this->isDirectiveOption($line)) {
                    if (!$this->lineChecker->isDirective($line)) {
                        $directive = $this->getCurrentDirective();
                        $this->isCode = $directive !== null ? $directive->wantCode() : false;
                        $this->setState(State::BEGIN);

                        return false;
                    }

                    $this->flush();
                    $this->initDirective($line);
                }

                break;

            default:
                $this->environment->addError('Parser ended in an unexcepted state');
        }

        return true;
    }

    private function flush(): void
    {
        $node = null;

        $this->isCode = false;

        if ($this->hasBuffer()) {
            switch ($this->state) {
                case State::NORMAL:
                    $this->isCode = $this->prepareCode();

                    $node = new ParagraphNode(new SpanNode($this->environment, $this->buffer->getLinesString()));

                    break;
                case State::SEPARATOR:
                    $level = $this->environment->getLevel((string) $this->specialLetter);

                    $node = new SeparatorNode($level);

                    break;

                case State::CODE:
                case State::BLOCK:
                case State::LIST:
                case State::DEFINITION_LIST:
                case State::TABLE:
                case State::COMMENT:
                    $node = $this->subparser->build();

                    break;
                case State::TITLE:
                    $node = $this->subparser->build();
                    if ($node instanceof TitleNode === false) {
                        throw new RuntimeException('Expected a TitleNode');
                    }

                    $this->lastTitleNode = $node;
                    $this->document->addNode(new SectionBeginNode($node));
                    $this->openTitleNodes->append($node);

                    break;
            }
        }

        if ($this->directive !== null) {
            $currentDirective = $this->getCurrentDirective();

            if ($currentDirective !== null) {
                try {
                    $currentDirective->process(
                        $this->parser,
                        $node instanceof CodeNode ? $node : null,
                        $this->directive->getVariable(),
                        $this->directive->getData(),
                        $this->directive->getOptions()
                    );
                } catch (Throwable $e) {
                    $message = sprintf(
                        'Error while processing "%s" directive%s: %s',
                        $currentDirective->getName(),
                        $this->environment->getCurrentFileName() !== '' ? sprintf(
                            ' in "%s"',
                            $this->environment->getCurrentFileName()
                        ) : '',
                        $e->getMessage()
                    );

                    $this->environment->addError($message);
                }
            }

            if ($node instanceof CodeNode) {
                $node = null;
            }
        }

        $this->directive = null;

        if ($node !== null) {
            $this->document->addNode($node);
        }

        $this->init();
    }

    private function hasBuffer(): bool
    {
        return !$this->buffer->isEmpty() || $this->nodeBuffer !== null;
    }

    private function getCurrentDirective(): ?Directive
    {
        if ($this->directive === null) {
            return null;
        }

        $name = $this->directive->getName();

        return $this->directives[$name];
    }

    private function isDirectiveOption(string $line): bool
    {
        if ($this->directive === null) {
            return false;
        }

        $directiveOption = $this->lineDataParser->parseDirectiveOption($line);

        if ($directiveOption === null) {
            return false;
        }

        $this->directive->setOption($directiveOption->getName(), $directiveOption->getValue());

        return true;
    }

    private function initDirective(string $line): bool
    {
        $parserDirective = $this->lineDataParser->parseDirective($line);

        if ($parserDirective === null) {
            return false;
        }

        if (!isset($this->directives[$parserDirective->getName()])) {
            $message = sprintf(
                'Unknown directive: "%s" %sfor line "%s"',
                $parserDirective->getName(),
                $this->environment->getCurrentFileName() !== '' ? sprintf(
                    'in "%s" ',
                    $this->environment->getCurrentFileName()
                ) : '',
                $line
            );

            $this->environment->addError($message);

            return false;
        }

        $this->directive = $parserDirective;

        return true;
    }

    private function prepareCode(): bool
    {
        $lastLine = $this->buffer->getLastLine();

        if ($lastLine === null) {
            return false;
        }

        $trimmedLastLine = trim($lastLine);

        if (strlen($trimmedLastLine) >= 2) {
            if (substr($trimmedLastLine, -2) === '::') {
                if (trim($trimmedLastLine) === '::') {
                    $this->buffer->pop();
                } else {
                    $this->buffer->set($this->buffer->count() - 1, substr($trimmedLastLine, 0, -1));
                }

                return true;
            }
        }

        return false;
    }

    private function parseLink(string $line): bool
    {
        $link = $this->lineDataParser->parseLink($line);

        if ($link === null) {
            return false;
        }

        if ($link->getType() === Link::TYPE_ANCHOR) {
            $anchorNode = new AnchorNode($link->getName());

            $this->document->addNode($anchorNode);
        }

        $this->environment->setLink($link->getName(), $link->getUrl());

        return true;
    }

    private function endOpenSection(TitleNode $titleNode): void
    {
        $this->document->addNode(new SectionEndNode($titleNode));

        $key = array_search($titleNode, $this->openTitleNodes->getArrayCopy(), true);

        if ($key === false) {
            return;
        }

        unset($this->openTitleNodes[$key]);
    }

    public function mergeIncludedFiles(string $document): string
    {
        return preg_replace_callback(
            '/^\.\. include:: (.+)$/m',
            function ($match) {
                $path = $this->environment->absoluteRelativePath($match[1]);

                $origin = $this->environment->getOrigin();
                if (!$origin->has($path)) {
                    throw new RuntimeException(
                        sprintf('Include "%s" (%s) does not exist or is not readable.', $match[0], $path)
                    );
                }

                $contents = $origin->read($path);

                if ($contents === false) {
                    throw new RuntimeException(sprintf('Could not load file from path %s', $path));
                }

                return $this->mergeIncludedFiles($contents);
            },
            $document
        );
    }
}
