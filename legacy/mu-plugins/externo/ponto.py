import cv2
from pyzbar.pyzbar import decode
import requests
import time
import hashlib
import winsound  # Biblioteca nativa de som do Windows

# --- CONFIGURAÇÃO DE PRODUÇÃO ---
API_URL = "https://administrativo.cachoeiradogirassol.com.br/wp-json/meu-sistema/v1/registrar-ponto"
SHARED_SECRET = "x7k9PzL2mN5qR8vJ3wA6yB4cE1dF0gH"

# Hardware
# --- CÂMERA IP via RTSP (ativa) ---
RTSP_URL = "rtsp://192.168.1.102:554/user=umeb_password=ggt3Apld_channel=0_stream=0&onvif=0.sdp?real_stream"
# Resolução alvo após o recorte (largura, altura). Reduza para aliviar o processamento.
RESOLUCAO_PROCESSAMENTO = (960, 360)
DELAY_ENTRE_LEITURAS = 5

# --- CÂMERA USB (desativada) ---
# CAMERA_INDEX = 0  # Índice da câmera USB (0 = primeira câmera conectada)

# --- CONFIGURAÇÃO DA IMPRESSORA ---
# Nome da impressora no Windows (como aparece em "Dispositivos e Impressoras")
# Deixe None para detectar automaticamente impressora USB térmica
PRINTER_NAME = None  # Ex: "EPSON TM-T20" ou "POS-80C"

# Lista de palavras-chave para identificar impressoras térmicas USB
THERMAL_PRINTER_KEYWORDS = [
    'pos', 'thermal', 'termica', 'receipt', 'ticket', 'cupom',
    'tm-t', 'tm-u', 'epson', 'elgin', 'bematech', 'daruma', 
    'sweda', 'tanca', 'diebold', 'gertec', '80mm', '58mm',
    'usb', 'print', 'printer'
]

# Largura do papel em caracteres (80mm = aproximadamente 48 caracteres)
PAPER_WIDTH = 48

def listar_impressoras_usb():
    """
    Lista todas as impressoras instaladas no Windows e identifica quais são USB.
    Retorna lista de dicionários com informações de cada impressora.
    """
    try:
        import win32print
        
        impressoras = []
        flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        
        for printer in win32print.EnumPrinters(flags):
            printer_name = printer[2]
            
            try:
                # Abrir impressora para obter mais detalhes
                handle = win32print.OpenPrinter(printer_name)
                info = win32print.GetPrinter(handle, 2)
                win32print.ClosePrinter(handle)
                
                porta = info.get('pPortName', 'Desconhecida')
                driver = info.get('pDriverName', 'Desconhecido')
                status = info.get('Status', 0)
                
                # Verifica se é USB
                is_usb = 'usb' in porta.lower() or 'usb' in printer_name.lower()
                
                impressoras.append({
                    'nome': printer_name,
                    'porta': porta,
                    'driver': driver,
                    'status': status,
                    'is_usb': is_usb,
                    'is_termica': any(kw in printer_name.lower() or kw in driver.lower() 
                                     for kw in THERMAL_PRINTER_KEYWORDS)
                })
            except Exception as e:
                impressoras.append({
                    'nome': printer_name,
                    'porta': 'Erro ao ler',
                    'driver': 'Desconhecido',
                    'status': -1,
                    'is_usb': False,
                    'is_termica': False,
                    'erro': str(e)
                })
        
        return impressoras
        
    except ImportError:
        print("⚠️ pywin32 não instalado. Execute: pip install pywin32")
        return []
    except Exception as e:
        print(f"⚠️ Erro ao listar impressoras: {e}")
        return []

def detectar_impressora_termica():
    """
    Detecta automaticamente uma impressora térmica USB.
    Prioriza: 1) USB + térmica, 2) térmica, 3) USB, 4) padrão do Windows
    """
    global PRINTER_NAME
    
    if PRINTER_NAME:
        return PRINTER_NAME  # Já configurada manualmente
    
    impressoras = listar_impressoras_usb()
    
    if not impressoras:
        try:
            import win32print
            return win32print.GetDefaultPrinter()
        except:
            return None
    
    # Prioridade 1: USB E Térmica
    for imp in impressoras:
        if imp.get('is_usb') and imp.get('is_termica'):
            print(f"🖨️ Impressora térmica USB detectada: {imp['nome']}")
            return imp['nome']
    
    # Prioridade 2: Apenas térmica
    for imp in impressoras:
        if imp.get('is_termica'):
            print(f"🖨️ Impressora térmica detectada: {imp['nome']}")
            return imp['nome']
    
    # Prioridade 3: Apenas USB
    for imp in impressoras:
        if imp.get('is_usb'):
            print(f"🖨️ Impressora USB detectada: {imp['nome']}")
            return imp['nome']
    
    # Fallback: padrão do Windows
    try:
        import win32print
        default = win32print.GetDefaultPrinter()
        print(f"🖨️ Usando impressora padrão: {default}")
        return default
    except:
        return None

def mostrar_impressoras():
    """Exibe lista formatada de impressoras para debug."""
    print("\n📋 IMPRESSORAS DETECTADAS:")
    print("-" * 60)
    
    impressoras = listar_impressoras_usb()
    
    if not impressoras:
        print("   Nenhuma impressora encontrada!")
        return
    
    for i, imp in enumerate(impressoras, 1):
        usb_tag = "[USB]" if imp.get('is_usb') else ""
        termica_tag = "[TÉRMICA]" if imp.get('is_termica') else ""
        
        print(f"   {i}. {imp['nome']} {usb_tag} {termica_tag}")
        print(f"      Porta: {imp['porta']}")
        print(f"      Driver: {imp['driver']}")
        print()
    
    print("-" * 60)

def tocar_beep_sucesso():
    try:
        winsound.Beep(1000, 200)
    except:
        pass

def tocar_beep_erro():
    try:
        winsound.Beep(300, 500)
    except:
        pass

def centralizar_texto(texto, largura=PAPER_WIDTH):
    """Centraliza texto para impressão térmica"""
    return texto.center(largura)

def imprimir_comprovante(dados_impressao):
    """
    Imprime comprovante de ponto em impressora térmica 80mm.
    Usa a API do Windows para impressão genérica (compatível com qualquer impressora).
    
    Args:
        dados_impressao: dict com 'nome', 'hora', 'tipo', 'data_completa'
    """
    try:
        import win32print
        import win32ui
        from PIL import Image, ImageDraw, ImageFont, ImageWin
        
        nome = dados_impressao.get('nome', 'Funcionário')
        hora = dados_impressao.get('hora', '--h--')
        tipo = dados_impressao.get('tipo', 'registro')
        data_completa = dados_impressao.get('data_completa', '')
        
        # Configurações de impressão
        width_mm = 80
        dpi = 203  # DPI típico de impressoras térmicas
        width_px = int(width_mm * dpi / 25.4)  # Converter mm para pixels
        
        # Criar imagem para impressão
        # Altura estimada para o conteúdo
        height_px = 400
        img = Image.new('1', (width_px, height_px), color=1)  # 1-bit, fundo branco
        draw = ImageDraw.Draw(img)
        
        # Tentar carregar fonte, fallback para padrão
        try:
            font_title = ImageFont.truetype("arial.ttf", 24)
            font_subtitle = ImageFont.truetype("arial.ttf", 18)
            font_name = ImageFont.truetype("arialbd.ttf", 28)  # Bold para nome
            font_time = ImageFont.truetype("arialbd.ttf", 36)  # Grande para horário
            font_type = ImageFont.truetype("arial.ttf", 22)
            font_footer = ImageFont.truetype("arial.ttf", 16)
        except:
            font_title = ImageFont.load_default()
            font_subtitle = font_title
            font_name = font_title
            font_time = font_title
            font_type = font_title
            font_footer = font_title
        
        y = 10
        center_x = width_px // 2
        
        # Linha separadora superior
        draw.line([(10, y), (width_px - 10, y)], fill=0, width=2)
        y += 15
        
        # Título
        title = "CACHOEIRA DO GIRASSOL"
        bbox = draw.textbbox((0, 0), title, font=font_title)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), title, font=font_title, fill=0)
        y += 30
        
        # Subtítulo
        subtitle = "Controle de Ponto"
        bbox = draw.textbbox((0, 0), subtitle, font=font_subtitle)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), subtitle, font=font_subtitle, fill=0)
        y += 35
        
        # Linha separadora
        draw.line([(10, y), (width_px - 10, y)], fill=0, width=2)
        y += 25
        
        # Nome do funcionário
        bbox = draw.textbbox((0, 0), nome, font=font_name)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), nome, font=font_name, fill=0)
        y += 50
        
        # Horário (grande e centralizado)
        bbox = draw.textbbox((0, 0), hora, font=font_time)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), hora, font=font_time, fill=0)
        y += 50
        
        # Tipo de batida
        bbox = draw.textbbox((0, 0), tipo, font=font_type)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), tipo, font=font_type, fill=0)
        y += 40
        
        # Mensagem de sucesso
        msg = "Batida registrada com sucesso!"
        bbox = draw.textbbox((0, 0), msg, font=font_footer)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), msg, font=font_footer, fill=0)
        y += 30
        
        # Data completa
        bbox = draw.textbbox((0, 0), data_completa, font=font_footer)
        text_width = bbox[2] - bbox[0]
        draw.text((center_x - text_width // 2, y), data_completa, font=font_footer, fill=0)
        y += 25
        
        # Linha separadora inferior
        draw.line([(10, y), (width_px - 10, y)], fill=0, width=2)
        
        # Recortar imagem para altura real usada
        img = img.crop((0, 0, width_px, y + 20))
        
        # Obter impressora (auto-detecta USB térmica)
        printer_name = detectar_impressora_termica()
        if not printer_name:
            print("   ⚠️ Nenhuma impressora encontrada!")
            return False
        
        # Criar contexto de dispositivo
        hdc = win32ui.CreateDC()
        hdc.CreatePrinterDC(printer_name)
        
        # Iniciar trabalho de impressão
        hdc.StartDoc("Comprovante de Ponto")
        hdc.StartPage()
        
        # Imprimir imagem
        dib = ImageWin.Dib(img)
        dib.draw(hdc.GetHandleOutput(), (0, 0, img.width, img.height))
        
        # Finalizar
        hdc.EndPage()
        hdc.EndDoc()
        hdc.DeleteDC()
        
        print("   🖨️ Comprovante impresso com sucesso!")
        return True
        
    except ImportError as e:
        print(f"   ⚠️ Bibliotecas de impressão não instaladas: {e}")
        print("   Execute: pip install pywin32 pillow")
        imprimir_texto_simples(dados_impressao)
        return False
        
    except Exception as e:
        print(f"   ⚠️ Erro na impressão: {e}")
        imprimir_texto_simples(dados_impressao)
        return False

def imprimir_texto_simples(dados_impressao):
    """
    Fallback: Imprime via texto simples se a impressão gráfica falhar.
    Usa impressão RAW do Windows.
    """
    try:
        import win32print
        
        nome = dados_impressao.get('nome', 'Funcionário')
        hora = dados_impressao.get('hora', '--h--')
        tipo = dados_impressao.get('tipo', 'registro')
        data_completa = dados_impressao.get('data_completa', '')
        
        # Montar texto do comprovante
        linha = "=" * PAPER_WIDTH
        texto = f"""
{linha}
{centralizar_texto("CACHOEIRA DO GIRASSOL")}
{centralizar_texto("Controle de Ponto")}
{linha}

{centralizar_texto(nome)}

{centralizar_texto(hora)}
{centralizar_texto(tipo)}

{centralizar_texto("Batida registrada com sucesso!")}

{centralizar_texto(data_completa)}
{linha}


"""
        
        # Obter impressora (auto-detecta USB térmica)
        printer_name = detectar_impressora_termica()
        if not printer_name:
            print("   ⚠️ Nenhuma impressora encontrada!")
            return False
        
        # Enviar para impressora
        hprinter = win32print.OpenPrinter(printer_name)
        try:
            job = win32print.StartDocPrinter(hprinter, 1, ("Comprovante Ponto", None, "RAW"))
            try:
                win32print.StartPagePrinter(hprinter)
                win32print.WritePrinter(hprinter, texto.encode('cp850'))  # Encoding para impressoras térmicas
                win32print.EndPagePrinter(hprinter)
            finally:
                win32print.EndDocPrinter(hprinter)
        finally:
            win32print.ClosePrinter(hprinter)
        
        print("   🖨️ Comprovante impresso (modo texto)!")
        return True
        
    except Exception as e:
        print(f"   ⚠️ Impressão texto também falhou: {e}")
        return False

def registrar_ponto(qr_token):
    print(f"\n🚀 QR DETECTADO: {qr_token}")
    # Somente bipar após sucesso da API

    
    timestamp = int(time.time())
    
    # Assinatura: qr_token + timestamp + segredo
    payload_string = f"{qr_token}{timestamp}{SHARED_SECRET}"
    signature = hashlib.sha256(payload_string.encode('utf-8')).hexdigest()
    
    data = {
        "qr_token": qr_token,
        "timestamp": timestamp,
        "hash": signature
    }
    
    print(f"   Enviando para o servidor...")
    
    try:
        response = requests.post(API_URL, json=data, timeout=10)
        
        # Tenta ler a resposta JSON
        try:
            resp = response.json()
            if response.status_code == 200:
                print(f"   ✅ SUCESSO: {resp.get('mensagem')}")
                
                # Beep duplo de confirmação
                time.sleep(0.1)
                tocar_beep_sucesso()
                
                # IMPRESSÃO DO COMPROVANTE
                if 'impressao' in resp:
                    imprimir_comprovante(resp['impressao'])
                    
            else:
                print(f"   ⚠️ ERRO API: {resp.get('code')} - {resp.get('message')}")
                tocar_beep_erro()
        except:
            print(f"   ⚠️ RESPOSTA BRUTA (Não JSON): {response.text[:100]}")

    except Exception as e:
        print(f"   🔌 ERRO DE CONEXÃO: {e}")
        tocar_beep_erro()

def iniciar_totem():
    print("--- TOTEM PONTO (WINDOWS) ---")
    print("Aponte o código. Pressione 'q' na janela de vídeo para sair.")
    
    # Mostrar impressoras detectadas e selecionar automaticamente
    mostrar_impressoras()
    
    printer = detectar_impressora_termica()
    if printer:
        print(f"✅ Impressora selecionada: {printer}\n")
    else:
        print("⚠️ Nenhuma impressora USB encontrada. Comprovantes não serão impressos.\n")
    
    # --- CÂMERA IP via RTSP (ativa) ---
    print(f"📡 Conectando ao stream RTSP: {RTSP_URL}")
    cap = cv2.VideoCapture(RTSP_URL)

    # --- CÂMERA USB (desativada) ---
    # print(f"📷 Conectando à câmera USB (índice {CAMERA_INDEX})...")
    # cap = cv2.VideoCapture(CAMERA_INDEX, cv2.CAP_DSHOW)

    if not cap.isOpened():
        print(f"ERRO: Não foi possível conectar ao stream RTSP.")
        print(f"Verifique o endereço: {RTSP_URL}")
        print("Certifique-se de que a câmera está acessível na rede e o endereço está correto.")
        # print(f"ERRO: Não foi possível acessar a câmera USB (índice {CAMERA_INDEX}).")
        # print("Verifique se a câmera está conectada e tente mudar CAMERA_INDEX para 1 ou 2.")
        return

    ultimo_tempo_leitura = 0  # timestamp da última leitura bem-sucedida

    while True:
        success, frame = cap.read()
        if not success:
            # Não bloqueia a UI — usa waitKey ao invés de sleep
            if cv2.waitKey(100) & 0xFF == ord('q'):
                break
            continue

        # --- CÂMERA IP: recorte e redução de resolução (ativo) ---
        # Recorta apenas a metade inferior do frame (câmera exibe duas imagens empilhadas)
        altura = frame.shape[0]
        frame = frame[altura // 2:, :]
        # Reduz resolução para aliviar o processamento
        frame = cv2.resize(frame, RESOLUCAO_PROCESSAMENTO, interpolation=cv2.INTER_LINEAR)

        agora = time.time()
        em_cooldown = (agora - ultimo_tempo_leitura) < DELAY_ENTRE_LEITURAS

        if em_cooldown:
            # Exibe contagem regressiva na tela durante o cooldown
            restante = DELAY_ENTRE_LEITURAS - (agora - ultimo_tempo_leitura)
            cv2.putText(frame, f"Aguarde {restante:.1f}s", (10, 30),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 165, 255), 2)
        else:
            # Converte para escala de cinza APENAS para decodificação do QR
            gray_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)

            # Decodifica usando frame em preto e branco
            qr_codes = decode(gray_frame)

            # Se encontrou código
            for qr in qr_codes:
                conteudo = qr.data.decode('utf-8')

                # Desenha retângulo visual no frame colorido
                (x, y, w, h) = qr.rect
                cv2.rectangle(frame, (x, y), (x + w, y + h), (0, 255, 0), 3)
                cv2.putText(frame, "REGISTRANDO...", (x, y - 10),
                            cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 255, 0), 2)

                if conteudo:
                    registrar_ponto(conteudo)
                    ultimo_tempo_leitura = time.time()  # inicia cooldown
                    print(f"   [Aguardando {DELAY_ENTRE_LEITURAS}s...]")
                    break  # evita processar múltiplos QRs no mesmo frame

        # Mostra o vídeo em cores (sempre — mantém a janela responsiva)
        cv2.imshow('Leitor Ponto', frame)

        # Sai com a tecla 'q' — waitKey(1) mantém a UI viva
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break

    cap.release()
    cv2.destroyAllWindows()

if __name__ == "__main__":
    iniciar_totem()