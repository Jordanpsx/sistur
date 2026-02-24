import cv2

def listar_cameras():
    print("--- INICIANDO DIAGNÓSTICO DE CÂMERA ---")
    
    # Vamos testar os índices 0, 1 e 2
    for index in range(3):
        print(f"\nTentando acessar Câmera índice {index}...")
        
        # Tenta abrir sem forçar DSHOW (modo padrão)
        cap = cv2.VideoCapture(index)
        
        if not cap.isOpened():
            print(f"❌ Índice {index}: Não foi possível abrir (Não existe ou está ocupada).")
        else:
            ret, frame = cap.read()
            if ret:
                print(f"✅ Índice {index}: FUNCIONANDO! (Imagem detectada)")
                print(f"   Resolução: {int(cap.get(3))}x{int(cap.get(4))}")
                print("   Pressione 'q' na janela para fechar este teste.")
                
                while True:
                    ret, frame = cap.read()
                    if not ret: break
                    cv2.imshow(f'Teste Camera {index}', frame)
                    if cv2.waitKey(1) & 0xFF == ord('q'):
                        break
            else:
                print(f"⚠️ Índice {index}: Abre, mas retorna tela preta/vazia.")
            
            cap.release()
            cv2.destroyAllWindows()

if __name__ == "__main__":
    listar_cameras()