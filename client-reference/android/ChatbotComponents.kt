package com.syhnwu.posandroid.screens.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowForward
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.syhnwu.posandroid.network.CopilotProposal
import com.syhnwu.posandroid.network.FinancialCardDto
import com.syhnwu.posandroid.network.extractForecastData
import com.syhnwu.posandroid.network.extractRadarData
import com.syhnwu.posandroid.network.extractScenarioData
import com.syhnwu.posandroid.network.extractSummaryData
import com.syhnwu.posandroid.network.extractWarningData
import com.syhnwu.posandroid.network.extractFinancingReadinessData
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.style.TextOverflow
import com.syhnwu.posandroid.ui.theme.AppColor
import com.syhnwu.posandroid.viewmodel.ProposalUiStatus

@Composable
fun ChatBubble(
    message: ChatMessage,
    onNavigate: (String) -> Unit,
    onApproveProposal: ((CopilotProposal) -> Unit)? = null,
    onRejectProposal: ((CopilotProposal) -> Unit)? = null,
    proposalUiStatus: ProposalUiStatus = ProposalUiStatus.PENDING,
    isActionLoading: Boolean = false,
    receiptSummary: String? = null
) {
    val isUser = message.isUser

    // 👇 [MATA SHARINGAN V2] Scanner Super Cerdas!
    // Sekarang bisa nangkap [ROUTE:katalog] ATAUPUN [CTA_FITUR] yang bocor
    val routeRegex = Regex("""\[(?:ROUTE:)?(.*?)\]""")

    // Cari apakah ada tag dalam pesan
    val matchResult = routeRegex.find(message.text)

    // Teks bersih (Semua tag yang ada di dalam kurung siku dihapus)
    var cleanMessage = message.text.replace(routeRegex, "").trim()

    // 👇 [FIX BOCOR] Jaga-jaga kalau AI nulisnya [CTA_FITUR] persis seperti di gambar
    cleanMessage = cleanMessage.replace("[CTA_FITUR]", "", ignoreCase = true).trim()

    // Nama route yang ketangkap (misal "katalog", "riwayat", atau "CTA_FITUR")
    val rawTargetRoute = matchResult?.groupValues?.get(1)

    // 👇 [FIX SAKTI: SMART DETECTOR] Konversi tag CTA_FITUR dengan membaca isi chat!
    val targetRoute = if (rawTargetRoute.equals("CTA_FITUR", ignoreCase = true)) {
        val lowerText = cleanMessage.lowercase()
        when {
            lowerText.contains("katalog") || lowerText.contains("menu") || lowerText.contains("kategori") -> "katalog"
            lowerText.contains("bahan baku") || lowerText.contains("resep") -> "bahan_baku"
            lowerText.contains("stok") || lowerText.contains("inventory") -> "stok"
            lowerText.contains("riwayat") || lowerText.contains("history") -> "riwayat"
            lowerText.contains("laporan") || lowerText.contains("penjualan") -> "laporan"
            lowerText.contains("reservasi") -> "reservasi"
            lowerText.contains("pengeluaran") || lowerText.contains("biaya") -> "pengeluaran"
            lowerText.contains("kasbon") || lowerText.contains("hutang") -> "kasbon_hutang"
            lowerText.contains("kas kecil") -> "kas_kecil"
            lowerText.contains("absensi") || lowerText.contains("karyawan") -> "absensi"
            lowerText.contains("dapur") || lowerText.contains("kds") -> "kds"
            lowerText.contains("pelanggan") || lowerText.contains("member") -> "member_screen"
            lowerText.contains("pengaturan") || lowerText.contains("setting") -> "setting_screen"
            lowerText.contains("shift") || lowerText.contains("kasir") -> "tutup_kasir"
            else -> "laporan" // Fallback terakhir jika benar-benar tidak ada kata kunci yang cocok
        }
    } else {
        rawTargetRoute
    }

    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = if (isUser) Arrangement.End else Arrangement.Start
    ) {
        Column(
            modifier = Modifier.widthIn(max = 260.dp)
        ) {
            Box(
                modifier = Modifier
                    .background(
                        color = if (isUser) AppColor.PrimaryBlue else Color(0xFFE5E7EB),
                        shape = RoundedCornerShape(
                            topStart = 16.dp,
                            topEnd = 16.dp,
                            bottomStart = if (isUser) 16.dp else 4.dp,
                            bottomEnd = if (isUser) 4.dp else 16.dp
                        )
                    )
                    .padding(12.dp)
            ) {
                Text(
                    text = cleanMessage, // Tampilkan teks yang sudah bersih dari tag apapun
                    color = if (isUser) Color.White else Color.Black,
                    fontSize = 14.sp
                )
            }

            // 👇 [SULAP TOMBOL] Munculkan tombol JIKA AI ngasih kode Rute!
            if (!isUser && targetRoute != null) {
                Spacer(modifier = Modifier.height(4.dp))
                androidx.compose.material3.Button(
                    // 👇 [FIX SAFETY] Pasang sabuk pengaman Anti-Crash!
                    onClick = {
                        try {
                            if (targetRoute.isNotBlank() && targetRoute != "CTA_FITUR") {
                                // 👇 [FIX SAKTI: TRANSLATOR JALAN TOL] 👇
                                // Terjemahkan kode AI yang pendek menjadi format rute Navigation Compose yang utuh!
                                val finalRoute = when(targetRoute.lowercase()) {
                                    "katalog" -> "katalog?pkgName="
                                    // (Kosongkan argumen pkgName agar tidak error dan membuka tab utama katalog)

                                    "pos_transaction", "transaksi", "kasir" -> "pos_transaction?name=&table=&type=&id=0&directPay=false&notes=&phone=&date=&time=&pax=&source=transaksi"
                                    // (Rute transaksi minta argumen super panjang, kita wajib isi default-nya)

                                    "kitchen_chat", "chat_dapur" -> "kitchen_chat?orderId=0&orderUuid="

                                    else -> targetRoute // Sisanya (seperti "laporan", "riwayat", dll) sudah aman karena tidak butuh argumen
                                }

                                onNavigate(finalRoute)
                            }
                        } catch (e: Exception) {
                            android.util.Log.e("KACHING_AI", "AI Halusinasi Rute: $targetRoute")
                        }
                    },
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                        containerColor = AppColor.PrimaryBlue
                    ),
                    shape = RoundedCornerShape(8.dp),
                    modifier = Modifier.fillMaxWidth(),
                    contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp)
                ) {
                    Icon(
                        imageVector = Icons.Default.ArrowForward,
                        contentDescription = null,
                        modifier = Modifier.size(14.dp)
                    )
                    Spacer(Modifier.width(6.dp))

                    // Bikin teks tombolnya pintar sesuai tujuan
                    val btnText = when(targetRoute.lowercase()) { // 👇 Antisipasi case sensitif
                        "katalog" -> "Buka Menu Katalog"
                        "bahan_baku" -> "Buka Bahan Baku"
                        "stok" -> "Cek Stok Barang"
                        "riwayat" -> "Buka Riwayat Transaksi"
                        "tutup_kasir" -> "Buka Dashboard Shift"
                        "kds" -> "Buka Layar Dapur (KDS)"
                        "kitchen_chat", "chat_dapur" -> "Buka Kitchen Chat"
                        "reservasi" -> "Buat Reservasi Baru"
                        "laporan" -> "Lihat Laporan Penjualan"
                        "kasbon_hutang" -> "Buka Buku Kasbon"
                        "pengeluaran" -> "Catat Pengeluaran"
                        "kas_kecil" -> "Buka Kas Kecil"
                        "absensi" -> "Buka Presensi Karyawan"
                        "web_order" -> "Cek Pesanan Web"
                        "member_screen" -> "Data Pelanggan"
                        "setting_screen" -> "Buka Pengaturan"
                        else -> "Buka Fitur Terkait" // Fallback jika rute tidak dikenali
                    }
                    Text(btnText, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                }
            }

            // 🛡️ [C.2-B] Financial Card terikat langsung per assistant message spesifik
            if (!isUser && message.financialCard != null) {
                Spacer(modifier = Modifier.height(6.dp))
                FinancialCardDispatcher(card = message.financialCard)
            }

            // 🛡️ [B.10-B3] Proposal Card terikat langsung per assistant message spesifik
            if (!isUser && message.proposal != null) {
                Spacer(modifier = Modifier.height(6.dp))
                CopilotProposalCard(
                    proposal = message.proposal,
                    uiStatus = proposalUiStatus,
                    isActionLoading = isActionLoading,
                    receiptSummary = receiptSummary,
                    onApprove = { onApproveProposal?.invoke(message.proposal) },
                    onReject = { onRejectProposal?.invoke(message.proposal) }
                )
            }
        }
    }
}

@Composable
fun UpgradeUpsellCard(tier: String, onUpgradeClick: () -> Unit) {
    Surface(
        color = if (tier == "ULTIMATE") Color(0xFFFFF7ED) else Color(0xFFEFF6FF), // Oranye vs Biru
        shape = RoundedCornerShape(12.dp),
        border = BorderStroke(1.dp, if (tier == "ULTIMATE") Color(0xFFFED7AA) else Color(0xFFBFDBFE)),
        modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                "🚀 Fitur Khusus Paket $tier",
                fontWeight = FontWeight.ExtraBold,
                fontSize = 13.sp,
                color = if (tier == "ULTIMATE") Color(0xFFC2410C) else AppColor.PrimaryBlue
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                "Tingkatkan paket usahamu sekarang untuk membuka fitur ini dan fitur premium lainnya.",
                fontSize = 11.sp,
                textAlign = TextAlign.Center,
                lineHeight = 16.sp
            )
            Spacer(modifier = Modifier.height(12.dp))
            androidx.compose.material3.Button(
                onClick = onUpgradeClick,
                colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                    containerColor = if (tier == "ULTIMATE") Color(0xFFF97316) else AppColor.PrimaryBlue
                ),
                shape = RoundedCornerShape(8.dp),
                modifier = Modifier.fillMaxWidth().height(36.dp),
                contentPadding = PaddingValues(0.dp)
            ) {
                Text("Lihat Paket Langganan", fontWeight = FontWeight.Bold, fontSize = 12.sp)
            }
        }
    }
}

// =================================================================================================
// 🛡️ [B.10-B3] COPILOT ACTION PROPOSAL CARD & HUMAN APPROVAL UX
// =================================================================================================

data class FormattedProposalDetails(
    val title: String,
    val items: List<Pair<String, String>>,
    val fallbackNotice: String? = null
)

fun formatProposalRupiah(amount: Double): String {
    val longVal = amount.toLong()
    return "Rp" + String.format(java.util.Locale.US, "%,d", longVal).replace(',', '.')
}

fun formatCopilotActionTitle(action: String): String {
    return when (action.trim()) {
        "expense.create" -> "Catat Pengeluaran"
        "menu.price_change" -> "Ubah Harga Menu"
        "stock.adjust" -> "Penyesuaian Stok"
        "transaction.cancel" -> "Batalkan Transaksi"
        else -> action
    }
}

fun formatCopilotProposal(proposal: CopilotProposal): FormattedProposalDetails {
    val action = proposal.action.trim()
    val params = proposal.parameters

    return when (action) {
        "expense.create" -> {
            val items = mutableListOf<Pair<String, String>>()
            if (params != null) {
                val name = if (params.has("name") && !params.get("name").isJsonNull) params.get("name").asString else null
                if (!name.isNullOrBlank()) {
                    items.add("Keterangan" to name)
                }
                val amount = if (params.has("amount") && !params.get("amount").isJsonNull) {
                    try { params.get("amount").asDouble } catch (_: Exception) { params.get("amount").asString?.toDoubleOrNull() }
                } else null
                if (amount != null) {
                    items.add("Nominal" to formatProposalRupiah(amount))
                }
                val category = if (params.has("category") && !params.get("category").isJsonNull) params.get("category").asString else null
                if (!category.isNullOrBlank()) {
                    items.add("Kategori" to category)
                }
            }
            FormattedProposalDetails(
                title = "Catat Pengeluaran",
                items = items
            )
        }
        "menu.price_change" -> {
            val items = mutableListOf<Pair<String, String>>()
            if (params != null) {
                val menuName = if (params.has("menu_name") && !params.get("menu_name").isJsonNull) params.get("menu_name").asString else null
                val menuId = if (params.has("menu_item_id") && !params.get("menu_item_id").isJsonNull) {
                    params.get("menu_item_id").asString
                } else if (params.has("menu_id") && !params.get("menu_id").isJsonNull) {
                    params.get("menu_id").asString
                } else null

                if (!menuName.isNullOrBlank()) {
                    items.add("Menu" to menuName)
                } else if (!menuId.isNullOrBlank()) {
                    items.add("Menu" to "ID #$menuId")
                }

                val newPrice = if (params.has("new_price") && !params.get("new_price").isJsonNull) {
                    try { params.get("new_price").asDouble } catch (_: Exception) { params.get("new_price").asString?.toDoubleOrNull() }
                } else null
                if (newPrice != null) {
                    items.add("Harga Baru" to formatProposalRupiah(newPrice))
                }

                val reason = if (params.has("reason") && !params.get("reason").isJsonNull) params.get("reason").asString else null
                if (!reason.isNullOrBlank()) {
                    items.add("Alasan" to reason)
                }
            }
            FormattedProposalDetails(
                title = "Ubah Harga Menu",
                items = items
            )
        }
        "stock.adjust" -> {
            val items = mutableListOf<Pair<String, String>>()
            if (params != null) {
                val menuName = if (params.has("menu_name") && !params.get("menu_name").isJsonNull) params.get("menu_name").asString else null
                val menuId = if (params.has("menu_id") && !params.get("menu_id").isJsonNull) {
                    params.get("menu_id").asString
                } else if (params.has("inventory_id") && !params.get("inventory_id").isJsonNull) {
                    params.get("inventory_id").asString
                } else null

                if (!menuName.isNullOrBlank()) {
                    items.add("Menu" to menuName)
                } else if (!menuId.isNullOrBlank()) {
                    items.add("Menu" to "ID #$menuId")
                }

                val qty = if (params.has("quantity_change") && !params.get("quantity_change").isJsonNull) {
                    try { params.get("quantity_change").asDouble } catch (_: Exception) { params.get("quantity_change").asString?.toDoubleOrNull() }
                } else if (params.has("quantity") && !params.get("quantity").isJsonNull) {
                    try { params.get("quantity").asDouble } catch (_: Exception) { params.get("quantity").asString?.toDoubleOrNull() }
                } else null

                if (qty != null) {
                    val qtyStr = if (qty % 1.0 == 0.0) qty.toInt().toString() else qty.toString()
                    items.add("Jumlah" to qtyStr)
                }

                val typeRaw = if (params.has("type") && !params.get("type").isJsonNull) params.get("type").asString else null
                if (!typeRaw.isNullOrBlank()) {
                    val typeLabel = when (typeRaw.lowercase()) {
                        "increment" -> "Tambah Stok"
                        "decrement" -> "Kurang Stok"
                        "opname" -> "Stok Opname"
                        "adjustment" -> "Penyesuaian"
                        else -> typeRaw
                    }
                    items.add("Tipe" to typeLabel)
                }
            }
            FormattedProposalDetails(
                title = "Penyesuaian Stok",
                items = items
            )
        }
        "transaction.cancel" -> {
            val items = mutableListOf<Pair<String, String>>()
            if (params != null) {
                val trxId = if (params.has("transaction_id") && !params.get("transaction_id").isJsonNull) params.get("transaction_id").asString else null
                if (!trxId.isNullOrBlank()) {
                    items.add("ID Transaksi" to "#$trxId")
                }
                val reason = if (params.has("reason") && !params.get("reason").isJsonNull) params.get("reason").asString else null
                if (!reason.isNullOrBlank()) {
                    items.add("Alasan" to reason)
                }
            }
            FormattedProposalDetails(
                title = "Batalkan Transaksi",
                items = items
            )
        }
        else -> {
            FormattedProposalDetails(
                title = formatCopilotActionTitle(action),
                items = emptyList(),
                fallbackNotice = "Aksi memerlukan otorisasi sistem."
            )
        }
    }
}

/**
 * 🛡️ [B.10-B3] Proposal Card Composable.
 * Terikat per-pesan ChatMessage asisten.
 */
@Composable
fun CopilotProposalCard(
    proposal: CopilotProposal,
    uiStatus: ProposalUiStatus = ProposalUiStatus.PENDING,
    isActionLoading: Boolean = false,
    receiptSummary: String? = null,
    onApprove: () -> Unit = {},
    onReject: () -> Unit = {}
) {
    val details = remember(proposal) { formatCopilotProposal(proposal) }
    val decision = proposal.decision.uppercase()
    val isDecisionExecutable = decision == "REQUIRES_APPROVAL" || decision == "ALLOW"
    val isActionable = isDecisionExecutable && (uiStatus == ProposalUiStatus.PENDING || uiStatus == ProposalUiStatus.ERROR)

    Surface(
        shape = RoundedCornerShape(12.dp),
        color = Color(0xFFF8FAFC),
        border = BorderStroke(1.dp, Color(0xFFE2E8F0)),
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = 8.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            // Header Row: Status badge on left, Risk badge on right
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                val (badgeText, badgeBg, badgeTextColor) = when {
                    uiStatus == ProposalUiStatus.EXECUTED -> Triple("✓ Selesai", Color(0xFFDCFCE7), Color(0xFF15803D))
                    uiStatus == ProposalUiStatus.REJECTED -> Triple("✗ Ditolak", Color(0xFFF1F5F9), Color(0xFF64748B))
                    uiStatus == ProposalUiStatus.EXPIRED -> Triple("⚠ Kedaluwarsa", Color(0xFFFEF3C7), Color(0xFFB45309))
                    decision == "DENY" -> Triple("Ditolak Kebijakan", Color(0xFFFEE2E2), Color(0xFFB91C1C))
                    decision == "UNSUPPORTED" -> Triple("Tidak Didukung", Color(0xFFF1F5F9), Color(0xFF475569))
                    else -> Triple("Perlu Persetujuan", Color(0xFFDBEAFE), Color(0xFF1D4ED8))
                }

                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = badgeBg
                ) {
                    Text(
                        text = badgeText,
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        color = badgeTextColor,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp)
                    )
                }

                if (!proposal.riskLevel.isNullOrBlank()) {
                    val risk = proposal.riskLevel.uppercase()
                    val (riskBg, riskText) = when (risk) {
                        "HIGH", "CRITICAL" -> Color(0xFFFEE2E2) to Color(0xFFB91C1C)
                        "MEDIUM" -> Color(0xFFFEF3C7) to Color(0xFFB45309)
                        else -> Color(0xFFF1F5F9) to Color(0xFF475569)
                    }
                    Surface(
                        shape = RoundedCornerShape(6.dp),
                        color = riskBg
                    ) {
                        Text(
                            text = "Risiko: ${proposal.riskLevel}",
                            fontSize = 10.sp,
                            fontWeight = FontWeight.Medium,
                            color = riskText,
                            modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                        )
                    }
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            // Action Title
            Text(
                text = details.title,
                fontSize = 14.sp,
                fontWeight = FontWeight.Bold,
                color = Color(0xFF1E293B)
            )

            Spacer(modifier = Modifier.height(6.dp))

            // Parameter items
            details.items.forEach { (label, value) ->
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(vertical = 2.dp),
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    Text(
                        text = label,
                        fontSize = 12.sp,
                        color = Color(0xFF64748B)
                    )
                    Text(
                        text = value,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = Color(0xFF1E293B)
                    )
                }
            }

            if (details.fallbackNotice != null) {
                Text(
                    text = details.fallbackNotice,
                    fontSize = 12.sp,
                    color = Color(0xFF64748B),
                    modifier = Modifier.padding(vertical = 2.dp)
                )
            }

            // Role metadata
            if (!proposal.requiredRole.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(4.dp))
                Text(
                    text = "Otorisasi Minimal: ${proposal.requiredRole}",
                    fontSize = 11.sp,
                    color = Color(0xFF94A3B8)
                )
            }

            // Receipt summary on executed status
            if (uiStatus == ProposalUiStatus.EXECUTED && !receiptSummary.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(6.dp))
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = Color(0xFFF0FDF4),
                    border = BorderStroke(1.dp, Color(0xFFBBF7D0)),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text(
                        text = receiptSummary,
                        fontSize = 11.sp,
                        color = Color(0xFF15803D),
                        modifier = Modifier.padding(6.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(10.dp))

            // Bottom Actions / Status
            when {
                uiStatus == ProposalUiStatus.APPROVING -> {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.Center
                    ) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(16.dp),
                            strokeWidth = 2.dp,
                            color = AppColor.PrimaryBlue
                        )
                        Spacer(modifier = Modifier.width(8.dp))
                        Text("Memproses persetujuan...", fontSize = 12.sp, color = AppColor.PrimaryBlue)
                    }
                }
                uiStatus == ProposalUiStatus.REJECTING -> {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.Center
                    ) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(16.dp),
                            strokeWidth = 2.dp,
                            color = Color(0xFF64748B)
                        )
                        Spacer(modifier = Modifier.width(8.dp))
                        Text("Membatalkan proposal...", fontSize = 12.sp, color = Color(0xFF64748B))
                    }
                }
                uiStatus == ProposalUiStatus.EXECUTED -> {
                    Text(
                        text = "Berhasil dijalankan",
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF16A34A),
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                uiStatus == ProposalUiStatus.REJECTED -> {
                    Text(
                        text = "Proposal ditolak",
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Medium,
                        color = Color(0xFF64748B),
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                uiStatus == ProposalUiStatus.EXPIRED -> {
                    Text(
                        text = "Persetujuan ini sudah kedaluwarsa.",
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Medium,
                        color = Color(0xFFD97706),
                        textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                isActionable -> {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedButton(
                            onClick = onReject,
                            enabled = !isActionLoading,
                            shape = RoundedCornerShape(8.dp),
                            colors = ButtonDefaults.outlinedButtonColors(
                                contentColor = Color(0xFF64748B)
                            ),
                            border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
                            contentPadding = PaddingValues(vertical = 6.dp, horizontal = 12.dp),
                            modifier = Modifier.weight(1f).height(36.dp)
                        ) {
                            Text("Tolak", fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        }

                        Button(
                            onClick = onApprove,
                            enabled = !isActionLoading,
                            shape = RoundedCornerShape(8.dp),
                            colors = ButtonDefaults.buttonColors(
                                containerColor = AppColor.PrimaryBlue
                            ),
                            contentPadding = PaddingValues(vertical = 6.dp, horizontal = 12.dp),
                            modifier = Modifier.weight(1f).height(36.dp)
                        ) {
                            Text("Setujui", fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
                else -> {
                    // DENY atau UNSUPPORTED: Tidak menampilkan tombol aksi
                }
            }
        }
    }
}

/**
 * 🛡️ [B.10-B3] Copilot Action PIN Dialog.
 * Dialog PIN asinkron mandiri untuk otorisasi aksi Copilot.
 * PIN hanya bersifat transien di dalam composable dan dikirim ke callback.
 */
@Composable
fun CopilotActionPinDialog(
    subtitle: String,
    isLoading: Boolean,
    errorMessage: String?,
    onSubmitPin: (String) -> Unit,
    onCancel: () -> Unit
) {
    var pinValue by remember { mutableStateOf("") }

    LaunchedEffect(errorMessage) {
        if (!errorMessage.isNullOrBlank()) {
            pinValue = ""
        }
    }

    Dialog(onDismissRequest = {
        if (!isLoading) onCancel()
    }) {
        Surface(
            shape = RoundedCornerShape(16.dp),
            color = Color.White,
            modifier = Modifier
                .fillMaxWidth(0.95f)
                .padding(16.dp)
        ) {
            Column(
                modifier = Modifier.padding(20.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                // Header row
                Box(modifier = Modifier.fillMaxWidth()) {
                    Text(
                        text = "Otorisasi PIN",
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF1E293B),
                        modifier = Modifier.align(Alignment.CenterStart)
                    )
                    Surface(
                        modifier = Modifier
                            .align(Alignment.CenterEnd)
                            .size(32.dp)
                            .clickable(enabled = !isLoading) { onCancel() },
                        shape = CircleShape,
                        color = Color(0xFFF1F5F9)
                    ) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(
                                imageVector = Icons.Default.Close,
                                contentDescription = "Tutup",
                                tint = Color(0xFF64748B),
                                modifier = Modifier.size(16.dp)
                            )
                        }
                    }
                }

                Spacer(modifier = Modifier.height(8.dp))

                Text(
                    text = subtitle,
                    fontSize = 12.sp,
                    color = Color(0xFF64748B),
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth()
                )

                Spacer(modifier = Modifier.height(16.dp))

                // 6-dot indicator
                Row(
                    horizontalArrangement = Arrangement.spacedBy(14.dp),
                    modifier = Modifier.padding(vertical = 8.dp)
                ) {
                    repeat(6) { index ->
                        val isFilled = index < pinValue.length
                        val hasError = !errorMessage.isNullOrBlank()
                        val dotColor = when {
                            hasError -> Color(0xFFEF4444)
                            isFilled -> AppColor.PrimaryBlue
                            else -> Color.Transparent
                        }
                        val borderColor = when {
                            hasError -> Color(0xFFEF4444)
                            isFilled -> AppColor.PrimaryBlue
                            else -> Color(0xFFCBD5E1)
                        }

                        Box(
                            modifier = Modifier
                                .size(16.dp)
                                .background(dotColor, CircleShape)
                                .border(1.5.dp, borderColor, CircleShape)
                        )
                    }
                }

                // Error / Loading indicator area
                Box(
                    modifier = Modifier
                        .height(28.dp)
                        .fillMaxWidth(),
                    contentAlignment = Alignment.Center
                ) {
                    if (isLoading) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(18.dp),
                            strokeWidth = 2.dp,
                            color = AppColor.PrimaryBlue
                        )
                    } else if (!errorMessage.isNullOrBlank()) {
                        Text(
                            text = errorMessage,
                            color = Color(0xFFDC2626),
                            fontSize = 12.sp,
                            fontWeight = FontWeight.Medium,
                            textAlign = TextAlign.Center
                        )
                    }
                }

                Spacer(modifier = Modifier.height(8.dp))

                // Numpad 0-9
                val keys = listOf("1", "2", "3", "4", "5", "6", "7", "8", "9", "", "0", "BACK")
                keys.chunked(3).forEach { row ->
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(vertical = 4.dp),
                        horizontalArrangement = Arrangement.SpaceEvenly
                    ) {
                        row.forEach { key ->
                            if (key.isEmpty()) {
                                Spacer(modifier = Modifier.size(56.dp))
                            } else {
                                Surface(
                                    modifier = Modifier
                                        .size(56.dp)
                                        .clip(CircleShape)
                                        .clickable(enabled = !isLoading) {
                                            if (key == "BACK") {
                                                if (pinValue.isNotEmpty()) {
                                                    pinValue = pinValue.dropLast(1)
                                                }
                                            } else {
                                                if (pinValue.length < 6) {
                                                    val newPin = pinValue + key
                                                    pinValue = newPin
                                                    if (newPin.length == 6) {
                                                        onSubmitPin(newPin)
                                                    }
                                                }
                                            }
                                        },
                                    color = if (key == "BACK") Color(0xFFF8FAFC) else Color(0xFFF1F5F9),
                                    shape = CircleShape
                                ) {
                                    Box(contentAlignment = Alignment.Center) {
                                        if (key == "BACK") {
                                            Text(
                                                text = "⌫",
                                                fontSize = 18.sp,
                                                fontWeight = FontWeight.Bold,
                                                color = Color(0xFF475569)
                                            )
                                        } else {
                                            Text(
                                                text = key,
                                                fontSize = 20.sp,
                                                fontWeight = FontWeight.SemiBold,
                                                color = Color(0xFF1E293B)
                                            )
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// =================================================================================================
// 🛡️ [C.2-B] FINANCIAL CARDS UI COMPOSABLES (FLAT NEOBRUTALISM THEME)
// =================================================================================================

private data class CardColorPalette(
    val badgeText: String,
    val badgeBg: Color,
    val badgeTextColor: Color,
    val borderColor: Color,
    val cardBg: Color
)

@Composable
fun FinancialCardDispatcher(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    when (card.type.lowercase().trim()) {
        "summary" -> FinancialSummaryMetricCard(card = card, modifier = modifier)
        "forecast" -> FinancialForecastMetricCard(card = card, modifier = modifier)
        "scenario" -> FinancialScenarioMetricCard(card = card, modifier = modifier)
        "warning" -> FinancialWarningAlertCard(card = card, modifier = modifier)
        "radar" -> FinancialRadarAlertCard(card = card, modifier = modifier)
        "financing_readiness", "readiness" -> FinancialReadinessMetricCard(card = card, modifier = modifier)
        else -> FinancialGenericCard(card = card, modifier = modifier)
    }
}

@Composable
fun FinancialSummaryMetricCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    val summary = remember(card) { card.extractSummaryData() }

    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color(0xFFF8FAFC),
        border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = card.title.ifBlank { "Ringkasan Penjualan" },
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A)
                )
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = Color(0xFFDCFCE7)
                ) {
                    Text(
                        text = "PENJUALAN",
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF15803D),
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            Text(
                text = if (summary.formattedSales.isNotBlank()) summary.formattedSales else formatProposalRupiah(summary.todaySales),
                fontSize = 20.sp,
                fontWeight = FontWeight.ExtraBold,
                color = Color(0xFF0F172A)
            )

            if (summary.date.isNotBlank()) {
                Spacer(modifier = Modifier.height(4.dp))
                Text(
                    text = "Tanggal: ${summary.date}",
                    fontSize = 11.sp,
                    color = Color(0xFF64748B)
                )
            }
        }
    }
}

@Composable
fun FinancialWarningAlertCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    val warningData = remember(card) { card.extractWarningData() }
    val severity = (card.severity ?: "warning").lowercase().trim()

    val palette = when (severity) {
        "critical" -> CardColorPalette("🚨 KRITIS", Color(0xFFFEE2E2), Color(0xFFB91C1C), Color(0xFFFCA5A5), Color(0xFFFEF2F2))
        "watch" -> CardColorPalette("👁️ PANTAU", Color(0xFFDBEAFE), Color(0xFF1D4ED8), Color(0xFF93C5FD), Color(0xFFEFF6FF))
        else -> CardColorPalette("⚠️ PERINGATAN", Color(0xFFFEF3C7), Color(0xFFB45309), Color(0xFFFCD34D), Color(0xFFFFFBEB))
    }

    Surface(
        shape = RoundedCornerShape(10.dp),
        color = palette.cardBg,
        border = BorderStroke(1.5.dp, palette.borderColor),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = card.title.ifBlank { "Peringatan Finansial" },
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A)
                )
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = palette.badgeBg
                ) {
                    Text(
                        text = palette.badgeText,
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = palette.badgeTextColor,
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            if (warningData.warnings.isEmpty()) {
                Text(
                    text = "Tidak ada anomali atau peringatan aktif.",
                    fontSize = 12.sp,
                    color = Color(0xFF475569)
                )
            } else {
                warningData.warnings.forEachIndexed { index, w ->
                    if (index > 0) {
                        Spacer(modifier = Modifier.height(8.dp))
                    }
                    Column(
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(Color.White, RoundedCornerShape(6.dp))
                            .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(6.dp))
                            .padding(8.dp)
                    ) {
                        if (w.title.isNotBlank()) {
                            Text(
                                text = w.title,
                                fontSize = 12.sp,
                                fontWeight = FontWeight.SemiBold,
                                color = Color(0xFF1E293B)
                            )
                        }
                        if (w.message.isNotBlank()) {
                            Spacer(modifier = Modifier.height(2.dp))
                            Text(
                                text = w.message,
                                fontSize = 11.sp,
                                color = Color(0xFF475569),
                                lineHeight = 15.sp
                            )
                        }
                        if (w.actual != null || w.baseline != null) {
                            Spacer(modifier = Modifier.height(4.dp))
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween
                            ) {
                                if (w.actual != null) {
                                    Text(
                                        text = "Aktual: " + if (w.unit == "PERCENT") "${w.actual}%" else formatProposalRupiah(w.actual),
                                        fontSize = 11.sp,
                                        fontWeight = FontWeight.Medium,
                                        color = palette.badgeTextColor
                                    )
                                }
                                if (w.baseline != null) {
                                    Text(
                                        text = "Target: " + if (w.unit == "PERCENT") "${w.baseline}%" else formatProposalRupiah(w.baseline),
                                        fontSize = 11.sp,
                                        color = Color(0xFF64748B)
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun FinancialForecastMetricCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    val forecast = remember(card) { card.extractForecastData() }
    val isNetPositive = forecast.projectedNetCashFlow >= 0

    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.White,
        border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = card.title.ifBlank { "Proyeksi Arus Kas" },
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A)
                )
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = Color(0xFFEFF6FF)
                ) {
                    Text(
                        text = "${forecast.horizonDays} HARI",
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF1D4ED8),
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            Text(
                text = "Net Arus Kas Proyeksi",
                fontSize = 11.sp,
                color = Color(0xFF64748B)
            )
            Text(
                text = (if (isNetPositive) "+" else "") + formatProposalRupiah(forecast.projectedNetCashFlow),
                fontSize = 18.sp,
                fontWeight = FontWeight.ExtraBold,
                color = if (isNetPositive) Color(0xFF16A34A) else Color(0xFFDC2626)
            )

            Spacer(modifier = Modifier.height(8.dp))

            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Color(0xFFF8FAFC), RoundedCornerShape(6.dp))
                    .padding(8.dp),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Column {
                    Text(text = "Estimasi Masuk", fontSize = 10.sp, color = Color(0xFF64748B))
                    Text(
                        text = formatProposalRupiah(forecast.projectedInflow),
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF16A34A)
                    )
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(text = "Estimasi Keluar", fontSize = 10.sp, color = Color(0xFF64748B))
                    Text(
                        text = formatProposalRupiah(forecast.projectedOutflow),
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFFDC2626)
                    )
                }
            }

            if (forecast.startDate.isNotBlank() && forecast.endDate.isNotBlank()) {
                Spacer(modifier = Modifier.height(6.dp))
                Text(
                    text = "Periode: ${forecast.startDate} s/d ${forecast.endDate}",
                    fontSize = 10.sp,
                    color = Color(0xFF94A3B8)
                )
            }
        }
    }
}

@Composable
fun FinancialScenarioMetricCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    val scenario = remember(card) { card.extractScenarioData() }
    val baseline = scenario.baseline
    val result = scenario.result
    val delta = scenario.delta

    val scenarioLabel = when (scenario.scenarioType.uppercase().trim()) {
        "PRICE_CHANGE" -> "Ubah Harga"
        "COGS_CHANGE" -> "Ubah HPP"
        "EXPENSE_CHANGE" -> "Ubah Beban"
        else -> scenario.scenarioType.ifBlank { "Simulasi" }
    }

    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.White,
        border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = card.title.ifBlank { "Simulasi Finansial" },
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A)
                )
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = Color(0xFFF3E8FF)
                ) {
                    Text(
                        text = scenarioLabel.uppercase(),
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF7E22CE),
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            if (delta != null) {
                val isProfitPositive = delta.netProfit >= 0
                val deltaSign = if (isProfitPositive) "+" else ""
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(
                            if (isProfitPositive) Color(0xFFDCFCE7) else Color(0xFFFEE2E2),
                            RoundedCornerShape(6.dp)
                        )
                        .padding(horizontal = 8.dp, vertical = 6.dp),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        text = "Estimasi Dampak Laba:",
                        fontSize = 11.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = Color(0xFF1E293B)
                    )
                    Text(
                        text = "$deltaSign${formatProposalRupiah(delta.netProfit)} (${String.format(java.util.Locale.US, "%.1f", delta.netProfitPercent)}%)",
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = if (isProfitPositive) Color(0xFF15803D) else Color(0xFFB91C1C)
                    )
                }
                Spacer(modifier = Modifier.height(8.dp))
            }

            if (baseline != null && result != null) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFF8FAFC), RoundedCornerShape(6.dp))
                        .padding(8.dp)
                ) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Text(text = "Metrik", fontSize = 10.sp, fontWeight = FontWeight.Bold, color = Color(0xFF64748B))
                        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                            Text(text = "Baseline", fontSize = 10.sp, fontWeight = FontWeight.Bold, color = Color(0xFF64748B))
                            Text(text = "Hasil", fontSize = 10.sp, fontWeight = FontWeight.Bold, color = Color(0xFF0F172A))
                        }
                    }
                    Spacer(modifier = Modifier.height(4.dp))
                    ScenarioRowItem(
                        label = "Omzet",
                        base = formatProposalRupiah(baseline.revenue),
                        res = formatProposalRupiah(result.revenue)
                    )
                    ScenarioRowItem(
                        label = "Laba Bersih",
                        base = formatProposalRupiah(baseline.netProfit),
                        res = formatProposalRupiah(result.netProfit)
                    )
                    ScenarioRowItem(
                        label = "Margin Bersih",
                        base = "${String.format(java.util.Locale.US, "%.1f", baseline.netMargin)}%",
                        res = "${String.format(java.util.Locale.US, "%.1f", result.netMargin)}%"
                    )
                }
            }
        }
    }
}

@Composable
private fun ScenarioRowItem(label: String, base: String, res: String) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 2.dp),
        horizontalArrangement = Arrangement.SpaceBetween
    ) {
        Text(text = label, fontSize = 11.sp, color = Color(0xFF475569))
        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            Text(text = base, fontSize = 11.sp, color = Color(0xFF64748B))
            Text(text = res, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = Color(0xFF0F172A))
        }
    }
}

@Composable
fun FinancialRadarAlertCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    val radarData = remember(card) { card.extractRadarData() }

    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color(0xFFF8FAFC),
        border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = card.title.ifBlank { "Radar Pasar & Bisnis" },
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A)
                )
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = Color(0xFFFEF3C7)
                ) {
                    Text(
                        text = radarData.signalCategory.ifBlank { "PASAR" }.uppercase(),
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFFB45309),
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            if (radarData.signals.isEmpty()) {
                Text(
                    text = "Tidak ada sinyal pasar aktif.",
                    fontSize = 12.sp,
                    color = Color(0xFF64748B)
                )
            } else {
                radarData.signals.forEachIndexed { index, sig ->
                    if (index > 0) {
                        Spacer(modifier = Modifier.height(6.dp))
                    }
                    Column(
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(Color.White, RoundedCornerShape(6.dp))
                            .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(6.dp))
                            .padding(8.dp)
                    ) {
                        Text(
                            text = sig.headline,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.Bold,
                            color = Color(0xFF1E293B)
                        )
                        if (sig.summary.isNotBlank()) {
                            Spacer(modifier = Modifier.height(2.dp))
                            Text(
                                text = sig.summary,
                                fontSize = 11.sp,
                                color = Color(0xFF475569),
                                lineHeight = 15.sp
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun FinancialGenericCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.White,
        border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Text(
                text = card.title,
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
                color = Color(0xFF0F172A)
            )
            if (!card.severity.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(4.dp))
                Text(
                    text = "Status: ${card.severity}",
                    fontSize = 11.sp,
                    color = Color(0xFF64748B)
                )
            }
        }
    }
}

@Composable
fun FinancialReadinessMetricCard(
    card: FinancialCardDto,
    modifier: Modifier = Modifier
) {
    val readiness = remember(card) { card.extractFinancingReadinessData() }

    val (badgeBg, badgeText, badgeColor) = when (readiness.band) {
        "HIGH_READINESS" -> Triple(Color(0xFFDCFCE7), "HIGH READINESS", Color(0xFF15803D))
        "MODERATE_READINESS" -> Triple(Color(0xFFFEF9C3), "MODERATE", Color(0xFF854D0E))
        "EARLY_STAGE_READINESS" -> Triple(Color(0xFFFFEDD5), "EARLY STAGE", Color(0xFF9A3412))
        else -> Triple(Color(0xFFFEE2E2), "NEEDS WORK", Color(0xFFB91C1C))
    }

    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color(0xFFF8FAFC),
        border = BorderStroke(1.dp, Color(0xFFCBD5E1)),
        shadowElevation = 1.dp,
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            // Header
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = card.title.ifBlank { "Financing Readiness" },
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A)
                )
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = badgeBg
                ) {
                    Text(
                        text = badgeText,
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = badgeColor,
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            // Score display: "84 / 100"
            Row(
                verticalAlignment = Alignment.Bottom
            ) {
                Text(
                    text = "${readiness.score}",
                    fontSize = 24.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = Color(0xFF0F172A)
                )
                Text(
                    text = " / 100",
                    fontSize = 14.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = Color(0xFF64748B),
                    modifier = Modifier.padding(bottom = 2.dp)
                )
            }

            Spacer(modifier = Modifier.height(10.dp))
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(1.dp)
                    .background(Color(0xFFE2E8F0))
            )
            Spacer(modifier = Modifier.height(8.dp))

            // 5 Pillars
            ReadinessPillarRow("Revenue Stability (25%)", readiness.revenueStability.score)
            ReadinessPillarRow("Margin Health (20%)", readiness.marginHealth.score)
            ReadinessPillarRow("Cash Discipline (25%)", readiness.cashDiscipline.score)
            ReadinessPillarRow("Operational Integrity (15%)", readiness.operationalIntegrity.score)
            ReadinessPillarRow("Data Completeness (15%)", readiness.dataCompleteness.score)

            // Limitations / Data Quality Indicator
            if (readiness.limitations.isNotEmpty()) {
                Spacer(modifier = Modifier.height(8.dp))
                Surface(
                    shape = RoundedCornerShape(6.dp),
                    color = Color(0xFFFEF3C7).copy(alpha = 0.6f),
                    border = BorderStroke(1.dp, Color(0xFFFDE68A)),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Column(modifier = Modifier.padding(8.dp)) {
                        Text(
                            text = "Catatan Kelengkapan Data (${readiness.limitations.size})",
                            fontSize = 10.sp,
                            fontWeight = FontWeight.Bold,
                            color = Color(0xFF92400E)
                        )
                        Spacer(modifier = Modifier.height(2.dp))
                        readiness.limitations.take(3).forEach { lim ->
                            Text(
                                text = "• $lim",
                                fontSize = 9.sp,
                                color = Color(0xFFB45309),
                                maxLines = 2,
                                overflow = TextOverflow.Ellipsis
                            )
                        }
                    }
                }
            }

            // Disclaimer
            Spacer(modifier = Modifier.height(8.dp))
            Text(
                text = readiness.disclaimer.ifBlank {
                    "Indeks kesiapan data operasional UMKM, bukan persetujuan kredit (credit score) atau jaminan pembiayaan."
                },
                fontSize = 9.sp,
                fontStyle = FontStyle.Italic,
                color = Color(0xFF94A3B8),
                lineHeight = 12.sp
            )
        }
    }
}

@Composable
private fun ReadinessPillarRow(
    title: String,
    score: Int
) {
    val scoreColor = when {
        score >= 80 -> Color(0xFF16A34A)
        score >= 60 -> Color(0xFFCA8A04)
        else -> Color(0xFFDC2626)
    }

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 3.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text = title,
            fontSize = 11.sp,
            color = Color(0xFF334155),
            fontWeight = FontWeight.Medium
        )
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = "$score",
                fontSize = 12.sp,
                fontWeight = FontWeight.Bold,
                color = scoreColor
            )
            Text(
                text = " / 100",
                fontSize = 9.sp,
                color = Color(0xFF94A3B8)
            )
        }
    }
}
