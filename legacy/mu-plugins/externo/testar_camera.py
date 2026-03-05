import cv2

print("=== DIAGNÓSTICO DE CÂMERAS ===\n")
print("Testando índices de câmera (0 a 5)...\n")

encontradas = []

for i in range(6):
    cap = cv2.VideoCapture(i, cv2.CAP_DSHOW)  # CAP_DSHOW = melhor para Windows
    if cap.isOpened():
        ret, frame = cap.read()
        if ret:
            w = cap.get(cv2.CAP_PROP_FRAME_WIDTH)
            h = cap.get(cv2.CAP_PROP_FRAME_HEIGHT)
            print(f"  ✅ Índice {i}: FUNCIONANDO ({int(w)}x{int(h)})")
            encontradas.append(i)
        else:
            print(f"  ⚠️  Índice {i}: abre mas não lê frames")
        cap.release()
    else:
        print(f"  ❌ Índice {i}: não encontrado")

print()
if encontradas:
    print(f"Câmeras funcionando: índices {encontradas}")
    print(f"\n➡️  Use CAMERA_INDEX = {encontradas[0]} no ponto.py")
else:
    print("Nenhuma câmera encontrada!")
    print("Verifique se a webcam está conectada e os drivers instalados.")
