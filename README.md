
## 🛠️ Development Status

The PHP Image Board is an active project with multiple completed systems and several new features currently in development.  
This section provides a clear overview of what’s already working and what’s being built, so contributors and users know what to expect.

---

## 🚀 Project Development Progress

This section shows what systems are **completed** ✅ and which ones are still **in development** 🛠️.  
More features are also in the works beyond what’s listed here.

---

### ✅ Completed Systems
- [x] **User Registration & Login**
- [x] **Changing of Account E-mail Address**
- [x] **Uploading of a User Avatar**
- [x] **Sensitive Content System**  
  Users must input their birthday to view restricted content; otherwise, it remains hidden.
- [x] **Changing of Account Password**
- [x] **Ability to See and View Uploaded Images**
- [x] **Image Board Pagination System**
- [x] **Advanced Duplicate Image Detection**  
  To keep the board clean and reduce clutter, multiple techniques are used to detect duplicate or near-duplicate images:

  - **aHash (Average Hash)**  
    Creates a hash by converting the image to grayscale, resizing, and comparing pixel brightness against the average.  
    ✅ Good for detecting *simple duplicates* and *resized* versions of the same image.  

  - **pHash (Perceptual Hash)**  
    Analyzes the image’s frequency domain (using DCT) to produce a hash that represents its overall visual features.  
    ✅ Excellent for catching *visually similar images* that may be compressed, slightly altered, or watermarked.  

  - **dHash (Difference Hash)**  
    Looks at differences in brightness between adjacent pixels to create a hash.  
    ✅ Very effective for detecting images that have been *resized, cropped, or slightly modified*.

---

### 🛠️ Systems In Development
- [ ] **Image Editing**
- [ ] **Image Deletion** *(partially available)*
- [ ] **Image Up Vote**
- [ ] **Image Favorite**
- [ ] **Image Report**
- [ ] **Image Commenting System**
- [ ] **User Notification System**  
  Users will be able to receive notifications about their uploaded images or comments made to their uploaded images.
- [ ] **Duplicate & Visually Similar Image Detection Tool (Web Interface)**  
  Provides a human-friendly web interface to review flagged duplicates and visually similar images.
- [ ] **Moderation Panel**  
  Serves as a moderator and administrator interface depending on the user’s group.  

---

### 🔮 Future Features
In addition to the above, more features are currently planned and in the design phase.  
These include **expanded search tools, tag systems, improved mobile responsiveness, and enhanced performance optimizations**.  
Stay tuned for updates as the project continues to grow.

---

## 🔄 Duplicate Detection Workflow

The duplicate detection process balances automation with human review:

1. **Image Upload**  
2. **Generate Hashes** (aHash, pHash, dHash)  
3. **Compare Against Existing Database**  
4. **Flag Potential Duplicates**  
5. **Human Moderator Visual Review** (side-by-side tool)  
6. **Decision: Approve or Reject Upload**

This format ensures clarity and proper alignment on GitHub, while still showing the step-by-step process.

---

## 💡 Why This Matters

Duplicate detection is critical for maintaining a high-quality image board:

- 📦 **Storage Efficiency** → Prevents wasted space by avoiding identical or near-identical images.  
- 🧭 **Better User Experience** → Reduces clutter so users don’t have to scroll past repeated content.  
- 👥 **Community Value** → Encourages original contributions instead of reposts.  
- 🛠️ **Moderator Support** → Provides tools to quickly identify and review potential duplicates, reducing manual workload.  

By combining **automated algorithms** with **human review tools**, the system strikes a balance between speed, accuracy, and fairness.

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPLv3).  
See the [LICENSE](LICENSE) file for the full license text.

### Summary (Plain English)

- ✅ You may use, modify, and distribute this software.
- ✅ Any distributed version must also be open source under AGPLv3.
- ✅ Contributions are welcome through pull requests.
- ❌ You may **not** relicense this code under a closed-source or proprietary license.
- ❌ You may **not** copy parts of this project into another project unless that project is also AGPLv3-compatible.
- ✅ If you run this software on a server (e.g. as a hosted service), you **must** provide the source code — including any modifications — to your users.
