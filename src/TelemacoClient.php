<?php

namespace Infocamere\Telemaco;

use Carbon\Carbon;
use Infocamere\Telemaco\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\BrowserKit\HttpBrowser;

class TelemacoClient
{
    /**
     * @var HttpBrowser
     */
    protected $browser;
    
    public function __construct()
    {
        $this->browser = new HttpBrowser(HttpClient::create());
    }  

    /**
     * Effettua il login a Cert'ò
     * 
     * @param string $username
     * @param string $password
     * 
     * @return string
     */
    public function login($username, $password)
    {
        $message = json_decode('{"data":null,"codError":null,"message":null,"result":null}');

        $this->browser->request('GET', 'https://praticacdor.infocamere.it/ptco/common/Login.action');
        $this->browser->submitForm('Accedi', ['userid' => $username, 'password' => $password]);

        $text = $this->browser->getCrawler()->filter('body')->text();

        if (Str::contains($text, 'aperta')) {
            $this->browser->submitForm('Accedi', ['userid' => $username, 'password' => $password]);
            $text = $this->browser->getCrawler()->filter('body')->text();
        }

        if (Str::contains($text, 'completa')) {
            $message->message = $text;
            $message->codError = "AU03";
        }

        if (Str::contains($text, 'scaduta')) {
            $message->message = $text;
            $message->codError = "AU04";
        }
        
        if (Str::contains($text, 'scadenza')) {
            $this->browser->clickLink('OK');
        }

        if (Str::contains($text, 'abilitato')) {
            $message->message = "Utente {$username} non abilitato per la risorsa richiesta.";
            $message->codError = "AU08";
            $this->browser->clickLink('Logout');
        }

        if (Str::contains($text, 'riuscita')) {
            $message->message = "Autenticazione Telemaco non riuscita, utente e/o password errati.";
            $message->codError = "AU01";
        }

        if (Str::contains($text, 'nuova password')) {
            $message->message = "Autenticazione Telemaco non riuscita, password scaduta. Rinnovare la password accedendo al sito www.registroimprese.it.";
            $message->codError = "AU09";
        }
        
        $message->result = "OK";

        return $message;
    }

    /**
     * Chiude la sessione Telemaco/Cert'ò
     */
    public function logout()
    {
        $this->browser->request('GET', 'https://praticacdor.infocamere.it/ptco/eacologout');
    }

    /**
     * Ricava il fondo (voce diritti) del borsellino Telemaco
     * 
     * @return string
     */
    public function fondo()
    {      
        /*$diritti = $this->browser->getCrawler()->filter("td[width='125px']")->last()->text();

        return Str::substr($diritti, 2);*/

        $response = $this->browser->request('GET', 'https://mypage.infocamere.it/group/telemacopay/saldo', [
            'cookies' => $this->browser->getCookieJar()->all()
        ]);

        if ($response->getStatusCode() == 200) {
            $diritti = $this->browser->getCrawler()->filter("div.saldoCifra");

            $diritti_formatted = '0.0';

            if ($diritti->count() > 0) {
                $diritti_formatted = trim(Str::before(Str::replaceFirst(',', '.', Str::replaceFirst('.', '', $diritti->first()->text())), '€'));
            }
            else {
                $this->browser->request('GET', 'https://mypage.infocamere.it/group/telemacoufficio/saldo', [
                    'cookies' => $this->browser->getCookieJar()->all()
                ]);

                $diritti = $this->browser->getCrawler()->filter("div.saldoCifra");

                if ($diritti->count() > 0) {
                    $diritti_formatted = trim(Str::before(Str::replaceFirst(',', '.', Str::replaceFirst('.', '', $diritti->first()->text())), '€'));
                }
            }
        }
        else {
            $diritti_formatted = 0.0;
        }

        //$response = $this->browser->getResponse();

        return (float) $diritti_formatted;
    }

    /**
     * Scarica da Cert'ò la distinta camerale
     * 
     * @param string $codPratica
     * 
     * @return string
     */
    public function distinta($codPratica)
    {
        $this->dettaglioPratica($codPratica);

        $res = $this->browser->getResponse();

        $html = $res->getContent();

        $t = Str::before(trim($html), 'Distinta');
        $uri = trim(substr($t, strrpos($t, "=")+1, -2));
        
        $this->browser->request('GET', 'https://praticacdor.infocamere.it/ptco/FpDownloadFile?id='.$uri, [
            'cookies' => $this->browser->getCookieJar()->all()
        ]);

        $pdf = $this->browser->getResponse()->getContent();

        return $pdf;
    }

    /**
     * Scarica da Cert'ò tutti gli allegati della pratica
     * 
     * @param string $codPratica
     * @param boolean $rettifica
     * 
     * @return array
     * */
    public function allegati($codPratica, $rettifica = false)
    {
        $this->dettaglioPratica($codPratica, $rettifica);

        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $this->browser->getResponse()->getContent());
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        //$ret = [];

        $ret = $crawler->filter('.DisplaytagTable > tbody')->children()->each(function(Crawler $node, $i) {
            $descr = $node->children()->first()->filter('i')->text();
            
            $link = $node->children()->last()->children()->selectLink('Scarica');
            $fileName = $link->attr('title');
            $this->browser->click($link->link());
        
            $file = $this->browser->getResponse()->getContent();

            $a = [];
            $a['descr'] = $descr;
            $a['file_name'] = $fileName;
            $a['file'] = $file;

            return $a;
        });

        return $ret;
    }

    /**
     * Scarica da Cert'ò i documenti per la stampa in azienda (CO, copie, fatture)
     * 
     * @param string $codPratica
     * 
     * @return array
     */
    public function coe($codPratica)
    {
        $this->dettaglioPratica($codPratica);

        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $this->browser->getResponse()->getContent());
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $nco = $crawler->filter('#divNoteSportello')->nextAll()->first()->text();
        $nco = Str::after(Str::before($nco, '-'), 'to:');
        $nco = trim($nco);

        $f = $crawler->filter('#all tbody > tr')->each(function (Crawler $node, $i) use ($codPratica, $nco) {
            $pdf = $node->children()->first()->text();
            $pdf = empty($nco) ? Str::after($pdf, '_') : Str::after(Str::replaceFirst($codPratica, $nco, $pdf), '_');

            $s = $node->filter('img')->first()->extract(['onclick']);
            
            $ll = explode(',', str_replace('"', '', Str::after(Str::before($s[0], ')'), 'doStampaOnline(')));

            $this->browser->request('POST', '/ptco/common/StampaModelloOnline.action', [
                'cookies' => $this->browser->getCookieJar()->all(),
                'richiestaId' => $ll[0],
                'documentoId' => trim($ll[1]),
                'tipoDocumento' => trim($ll[2]),
                'user' => trim($ll[3]),
                'ente' => trim($ll[4]),
            ]);
                
            $res = $this->browser->getResponse()->getContent();
    
            $b64 = trim(Str::after(Str::before($res, '//var pdfDataB'), 'pdf_data ='));
            
            $bdata = substr($b64, 1, strlen($b64)-3);

            return [
                'id' => $ll[0], 
                'file' => $pdf, 
                'data' => $bdata
            ];      
        });

        return $f;
    }

    /**
     * Aggiorna la password Telemaco
     * 
     * @param string $username
     * @param string $password
     * @param string $newPassword
     * 
     * @return mixed
     */
    public function aggiornaPassword($username, $password, $newPassword = null)
    {
        if (is_null($newPassword)) {
            $c = [':', '?', '!', '.', '*'];
            shuffle($c);
            $n = rand(0, 4);
            $newPassword = str_shuffle(Str::random(11).$c[$n]);
        }

        $this->browser->request('GET', 'https://login.infocamere.it/eacologin/changePwd.action');
        $this->browser->submitForm('Conferma', [
            'userid' => $username, 
            'password' => $password, 
            'new_password' => $newPassword, 
            'cfr_password' => $newPassword
        ]);

        $text = $this->browser->getCrawler()->filter('body')->text();
        
        if (Str::contains($text, 'sostituita', true)) {            
            return [
                'username' => $username, 
                'password' => $newPassword, 
                'data_scadenza' => Carbon::now('Europe/Rome')->addMonths(6)->toDateString(),
                'message' => null,
            ];
        }
        else {
            return [
                'username' => $username,
                'password' => $password,
                'new_password' => $newPassword,
                'message' => $text,
            ];
        }
    }

    /**
     * Ricava i dati dell'utente Telemaco (nome, cognome, email, telefono)
     */
    public function datiTelemaco()
    {
        $this->browser->request('GET', 'https://webtelemaco.infocamere.it/spor/private/EsitoRicercaAnagrafica.action');

        $cognome = $this->browser->getCrawler()->filter('#ana_cognome')->attr('value');
        $nome = $this->browser->getCrawler()->filter('#ana_nome')->attr('value');
        $email = $this->browser->getCrawler()->filter('#ana_email')->attr('value');
        $telefono = $this->browser->getCrawler()->filterXPath('//input[@name="anagrafica.telefono"]')->attr('value');

        $r = [
            'nome' => $nome.' '.$cognome,
            'email' => $email,
            'telefono' => $telefono,
        ];

        return $r;
    }

    /**
     * Genera il file XML con i dati per il tipo specificato (co, va, dtr)
     * 
     * @param string $tipo
     * @param array $dati
     * 
     * @return array
     */
    public function xml(array $dati, $tipo = 'co')
    {
        $f = 'xml'.$tipo;
        
        return $this->$f($dati);
    }

    private function xmlco($dati)
    {
        $fp = "#####FINEPAGINA#####";
        
        $doc = new \DOMDocument();
        $doc->encoding = "UTF-8";
        $doc->formatOutput = true;
        $doc->loadXML(file_get_contents(dirname(__FILE__)."/template/Mbase_PTCO_CO.xml"));
        $provincia = $doc->getElementsByTagName('Provincia')->item(0);
        $provincia->setAttribute('siglaProv', $dati['prov']);
        $numero = $doc->getElementsByTagName('Numero')->item(0);
        $numero->nodeValue = $dati['rea'];
        $codfisc = $doc->getElementsByTagName('CodiceFiscale')->item(0);
        $codfisc->nodeValue = $dati['cf'];
        $denom = $doc->getElementsByTagName('Denominazione')->item(0);
        $denom->nodeValue = htmlentities($dati['denominazione'], ENT_XML1);
        $sped = $doc->getElementsByTagName('Speditore')->item(0);
        $sped->nodeValue = htmlentities($dati['speditore'], ENT_XML1);
        $dest = $doc->getElementsByTagName('Destinatario')->item(0);
        $dest->nodeValue = htmlentities($dati['destinatario'], ENT_XML1);                
        $pdest = $doc->getElementsByTagName('PaeseDestinazione')->item(0);
        $pdest->setAttribute('nomePaeseDestinazione', $dati['paese_destinazione']);
        $porig = explode(',', $dati['paese_origine']);
        $paesi_origine = $doc->getElementsByTagName('PaesiOrigine')->item(0);
        foreach ($porig as $po) {
            if (!empty(trim($po))) {
                $node = $doc->createElement("Paese");
                $node->nodeValue = trim($po);
                $paesi_origine->appendChild($node);
            }
        }
        $fatturato = $doc->getElementsByTagName('FatturatoTotale')->item(0);
        if (!empty($dati['valore_merce'])) {
            $fatturato->nodeValue = $dati['valore_merce'];
        }
        $trasp = $doc->getElementsByTagName('Trasporto')->item(0);
        $trasp->nodeValue = htmlentities($dati['trasporto'], ENT_XML1);
        $osservazioni = $doc->getElementsByTagName('Osservazioni')->item(0);
        $osservazioni->nodeValue = htmlentities($dati['osservazioni'], ENT_XML1);

        $dati_cert = $doc->getElementsByTagName('DATI-CERTIFICATO')->item(0);

        if (Str::contains($dati['merci'], $fp)) {
            $t6 = explode($fp, $dati['merci']);
            $t7 = explode($fp, $dati['quantita']);

            for ($i=0; $i < count($t6); $i++) {
                $noded = $doc->createElement("DettaglioMerci");
                $noded->nodeValue = htmlentities($t6[$i], ENT_XML1);
                $dati_cert->appendChild($noded);
            }

            for ($i=0; $i < count($t7); $i++) {
                $nodeq = $doc->createElement("Quantita");
                $nodeq->nodeValue = htmlentities($t7[$i], ENT_XML1);
                $dati_cert->appendChild($nodeq);
            }
        }
        else {
            $merci = $doc->createElement("DettaglioMerci");
            $merci->nodeValue = htmlentities($dati['merci'], ENT_XML1);
            $dati_cert->appendChild($merci);
            $quant = $doc->createElement("Quantita");
            $quant->nodeValue = htmlentities($dati['quantita'], ENT_XML1);
            $dati_cert->appendChild($quant);
        }

        $orme = 'ORCOM';

        if (strlen(trim($dati['modifiche_subite']))+strlen(trim($dati['azienda_modifiche'])) > 0) {
            $orme = 'MDCOM';
        }
        
        if (strlen(trim($dati['paese_extraue']))+strlen(trim($dati['documentazione_allegata'])) > 0)  {
            $orme = 'OREST';
        }        

        $ormerce = $doc->getElementsByTagName('ORIGINEMERCE')->item(0);
        $ormerce->nodeValue = $orme;

        $paese_ue = $doc->getElementsByTagName('PaeseComunitario')->item(0);
        $paese_ue->nodeValue = $dati['paese_comunitario'];
        $modprod = $doc->getElementsByTagName('ModalitaProduzione')->item(0);
        $modprod->nodeValue = $dati['modalita_produzione'];
        $azprod = $doc->getElementsByTagName('AziendaProduttrice')->item(0);
        $azprod->nodeValue = htmlentities($dati['azienda_produttrice'], ENT_XML1);
        $mod = $doc->getElementsByTagName('ModificheSubite')->item(0);
        $mod->nodeValue = $dati['modifiche_subite'];
        $azmod = $doc->getElementsByTagName('AziendaModifiche')->item(0);
        $azmod->nodeValue = htmlentities($dati['azienda_modifiche'], ENT_XML1);
        $paese_extraue = $doc->getElementsByTagName('PaeseExtraComunitario')->item(0);
        $paese_extraue->nodeValue = $dati['paese_extraue'];
        $docall = $doc->getElementsByTagName('DocumentazioneAllegata')->item(0);
        $docall->nodeValue = htmlentities($dati['documentazione_allegata'], ENT_XML1);

        $fatt = $doc->getElementsByTagName('RIEPILOGO-FATTURE')->item(0);

        foreach ($dati['fatture'] as $fattura) {
            $ff = $doc->createElement('Fattura');
            $h = $fatt->appendChild($ff);
            $h->setAttribute('numero', $fattura['numero']);
            $h->setAttribute('data', $fattura['data'].'+01:00');
        }

        return $doc->saveXML();
    }

    private function xmlva($dati)
    {
        $doc = new \DOMDocument();
        $doc->encoding = "UTF-8";
        $doc->formatOutput = true;
        $doc->loadXML(file_get_contents(dirname(__FILE__)."/template/Mbase_PTCO_VA.xml"));
        $provincia = $doc->getElementsByTagName('Provincia')->item(0);
        $provincia->setAttribute('siglaProv', $dati['prov']);
        $numero = $doc->getElementsByTagName('Numero')->item(0);
        $numero->nodeValue = $dati['rea'];
        $codfisc = $doc->getElementsByTagName('CodiceFiscale')->item(0);
        $codfisc->nodeValue = $dati['cf'];
        $denom = $doc->getElementsByTagName('Denominazione')->item(0);
        $denom->nodeValue = htmlentities($dati['denominazione'], ENT_XML1);
        $descr = $doc->getElementsByTagName('SoggettoRichiedente')->item(0);
        $descr->nodeValue = htmlentities($dati['descrizione'], ENT_XML1);
        $note = $doc->getElementsByTagName('NoteRichiesta')->item(0);
        $note->nodeValue = htmlentities($dati['note'], ENT_XML1);

        return $doc->saveXML();
    }

    private function xmldd($dati)
    {
        $doc = new \DOMDocument();
        $doc->encoding = "UTF-8";
        $doc->formatOutput = true;
        $doc->loadXML(file_get_contents(dirname(__FILE__)."/template/Mbase_PTCO_DD.xml"));
        $provincia = $doc->getElementsByTagName('Provincia')->item(0);
        $provincia->setAttribute('siglaProv', $dati['prov']);
        $numero = $doc->getElementsByTagName('Numero')->item(0);
        $numero->nodeValue = $dati['rea'];
        $codfisc = $doc->getElementsByTagName('CodiceFiscale')->item(0);
        $codfisc->nodeValue = $dati['cf'];
        $denom = $doc->getElementsByTagName('Denominazione')->item(0);
        $denom->nodeValue = htmlentities($dati['denominazione'], ENT_XML1);
        $descr = $doc->getElementsByTagName('DataDenuncia')->item(0);
        $descr->nodeValue = htmlentities(date('Y-m-d').'+01:00', ENT_XML1);
        
        $docden = $doc->getElementsByTagName('DocumentiDenunciati')->item(0);

        foreach ($dati['co'] as $co) {
            $dd = $doc->createElement('DocumentoDenunciato');
            $h = $docden->appendChild($dd);
            $h->setAttribute('identificativo', $co);
        }

        return $doc->saveXML();
    }

    private function xmldf($dati)
    {
        $doc = new \DOMDocument();
        $doc->encoding = "UTF-8";
        $doc->formatOutput = true;
        $doc->loadXML(file_get_contents(dirname(__FILE__)."/template/Mbase_PTCO_DF.xml"));
        $provincia = $doc->getElementsByTagName('Provincia')->item(0);
        $provincia->setAttribute('siglaProv', $dati['prov']);
        $numero = $doc->getElementsByTagName('Numero')->item(0);
        $numero->nodeValue = $dati['rea'];
        $codfisc = $doc->getElementsByTagName('CodiceFiscale')->item(0);
        $codfisc->nodeValue = $dati['cf'];
        $denom = $doc->getElementsByTagName('Denominazione')->item(0);
        $denom->nodeValue = htmlentities($dati['denominazione'], ENT_XML1);
        $descr = $doc->getElementsByTagName('DataDenuncia')->item(0);
        $descr->nodeValue = htmlentities(date('Y-m-d').'+01:00', ENT_XML1);
        
        $docden = $doc->getElementsByTagName('DocumentiDenunciati')->item(0);

        foreach ($dati['co'] as $co) {
            $dd = $doc->createElement('DocumentoDenunciato');
            $h = $docden->appendChild($dd);
            $h->setAttribute('identificativo', $co);
        }

        return $doc->saveXML();
    }

    private function xmldtr($dati)
    {
        $doc = new \DOMDocument();
        $doc->encoding = "UTF-8";
        $doc->formatOutput = true;
        $doc->loadXML(file_get_contents(dirname(__FILE__)."/template/Mbase_PTCO_DTR.xml"));
        $cciaa = $doc->getElementsByTagName('CciaaRiferimento')->item(0);
        $cciaa->nodeValue = $dati['cciaa'];
        $tipo = $doc->getElementsByTagName('TipoPratica')->item(0);
        $tipo->nodeValue = $dati['tipo'];
    }

    private function dettaglioPratica($codPratica, $rettifica = false)
    {
        $lista = $rettifica ? 'ListaPraticheDaRettificare' : 'ListaPraticheChiuse';
        $dettaglio = $rettifica ? 'DettaglioPraticaRettifica' : 'DettaglioPraticaChiusa';
        
        $this->browser->request('POST', 'https://praticacdor.infocamere.it/ptco/common/'.$lista.'.action', [
            'opzioneFiltro' => 'CODICE_PRATICA', 
            'valoreFiltro' => $codPratica, 
            'tipoPratica' => ''
        ]);

        $f = $this->browser->getCrawler()->selectLink($codPratica)->attr('href');

        $v = str_replace("'", '', substr($f, strpos($f, '(')+1, -1));

        $vv = explode(',', $v);

        $this->browser->request('GET', 'https://praticacdor.infocamere.it/ptco/common/'.$dettaglio.'.action?codPraticaSel='.$vv[0].'&pridPraticaSel='.$vv[1].'&pvPraticaSel='.$vv[2]);
    }
}